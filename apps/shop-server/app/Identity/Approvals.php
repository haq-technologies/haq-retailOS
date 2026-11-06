<?php

declare(strict_types=1);

namespace App\Identity;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Platform\Ulid;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Manager approvals (spec section 3) [F9, S3]. A manager taps their name tile and enters their 6-digit
 * PIN on the same screen; that grants a single-use approval for one action, bound when granted to the
 * cart line, item and price. The bind limit (60 seconds) covers only grant to bind; once bound, the
 * approval holds while the cart is open or parked, and changing that line voids it. It never changes the
 * cashier's session. An approval by a manager not signed in at this branch today is flagged [F8].
 */
final readonly class Approvals
{
    public function __construct(
        private Pins $pins,
        private Throttle $throttle,
        private Settings $settings,
        private AuditLog $audit,
    ) {}

    /**
     * @return array{id: string, token: string, expires: CarbonImmutable}
     */
    public function grant(
        Terminal $terminal,
        ?string $requestedBy,
        string $approverId,
        string $pin,
        ApprovalAction $action,
        ?string $subjectId = null,
        ?string $itemId = null,
        ?int $priceKobo = null,
    ): array {
        if ($this->throttle->terminalWaitSeconds($terminal->id) > 0) {
            throw new ApprovalRefused('Too many wrong PINs on this counter. Wait, then try again.');
        }
        $approver = User::query()->active()->find($approverId);
        if ($approver === null || ! $approver->role->canApprove()) {
            throw new ApprovalRefused('Only a manager or the owner can approve this.');
        }
        if ($this->throttle->lockedUntil($approver->id) !== null) {
            throw new ApprovalRefused('Too many wrong PINs. This manager is locked for now.');
        }

        $ok = $this->pins->check($pin, $approver->pin_hash);

        return DB::transaction(function () use ($ok, $terminal, $requestedBy, $approver, $action, $subjectId, $itemId, $priceKobo): array {
            $this->throttle->record($terminal->id, $approver->id, 'approval', $ok);
            if (! $ok) {
                $this->audit->record(new AuditEntry(action: 'identity.approval_failed', actorId: $requestedBy, terminalId: $terminal->id,
                    subjectType: 'user', subjectId: $approver->id, after: ['action' => $action->value]));
                throw new ApprovalRefused('That PIN is not right. Nothing was approved.');
            }

            $token = Str::random(48);
            $id = Ulid::generate();
            $now = CarbonImmutable::now();
            $flagged = ! $this->signedInToday($approver->id, $terminal->branch_id);

            DB::table('approvals')->insert([
                'id' => $id,
                'action' => $action->value,
                'subject_id' => $subjectId,
                'item_id' => $itemId,
                'price_kobo' => $priceKobo,
                'approver_id' => $approver->id,
                'requested_by' => $requestedBy,
                'terminal_id' => $terminal->id,
                'token_hash' => hash('sha256', $token),
                'flagged' => $flagged,
                'issued_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);

            $this->audit->record(new AuditEntry(action: 'identity.approval_granted', actorId: $approver->id, terminalId: $terminal->id,
                subjectType: 'approval', subjectId: $id,
                after: ['action' => $action->value, 'line' => $subjectId, 'item_id' => $itemId, 'price_kobo' => $priceKobo, 'requested_by' => $requestedBy, 'flagged' => $flagged]));

            return ['id' => $id, 'token' => $token, 'expires' => $now->addSeconds($this->bindSeconds())];
        });
    }

    /**
     * Binds an approval to the action it was granted for, once. Returns the approval's ID.
     */
    public function bind(Terminal $terminal, string $token, ApprovalAction $action, ?string $subjectId = null, ?string $itemId = null, ?int $priceKobo = null): string
    {
        return DB::transaction(function () use ($terminal, $token, $action, $subjectId, $itemId, $priceKobo): string {
            /** @var object{id: string, action: string, subject_id: ?string, item_id: ?string, price_kobo: ?int, terminal_id: string, issued_at: string, bound_at: ?string}|null $approval */
            $approval = DB::table('approvals')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();

            if ($approval === null || $approval->terminal_id !== $terminal->id) {
                throw new ApprovalRefused('This approval is not for this counter.');
            }
            if ($approval->bound_at !== null) {
                throw new ApprovalRefused('This approval has already been used.');
            }
            if (CarbonImmutable::parse($approval->issued_at)->addSeconds($this->bindSeconds())->isPast()) {
                throw new ApprovalRefused('This approval took too long to use. Ask the manager again.');
            }
            if ($approval->action !== $action->value || $approval->subject_id !== $subjectId || $approval->item_id !== $itemId
                || ($approval->price_kobo === null ? null : (int) $approval->price_kobo) !== $priceKobo) {
                throw new ApprovalRefused('The line changed after it was approved. Ask the manager again.');
            }

            DB::table('approvals')->where('id', $approval->id)->update(['bound_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.uP')]);
            $this->audit->record(new AuditEntry(action: 'identity.approval_bound', terminalId: $terminal->id, subjectType: 'approval', subjectId: $approval->id));

            return $approval->id;
        });
    }

    /** Until shifts exist (Phase 3), the business day starts at midnight in the business timezone. */
    private function signedInToday(string $userId, string $branchId): bool
    {
        $zone = $this->settings->get('business.timezone');
        $startOfDay = CarbonImmutable::now(is_string($zone) ? $zone : 'Africa/Lagos')->startOfDay()->utc();

        return DB::table('sign_in_attempts')
            ->join('terminals', 'terminals.id', '=', 'sign_in_attempts.terminal_id')
            ->where('terminals.branch_id', $branchId)
            ->where('sign_in_attempts.user_id', $userId)
            ->where('sign_in_attempts.kind', 'sign_in')
            ->where('sign_in_attempts.succeeded', true)
            ->where('sign_in_attempts.attempted_at', '>=', $startOfDay)
            ->exists();
    }

    private function bindSeconds(): int
    {
        $seconds = $this->settings->get('identity.approval_bind_seconds');

        return is_int($seconds) ? $seconds : 60;
    }
}
