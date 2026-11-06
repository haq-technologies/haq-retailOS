<?php

declare(strict_types=1);

namespace App\Sales;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Identity\ApprovalAction;
use App\Identity\ApprovalRefused;
use App\Identity\Approvals;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Organisation\Models\Terminal;
use App\Platform\Ulid;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Held sales and Awaiting transfer (spec sections 3, 7 and 9) [F12, S2].
 *
 * - Hold parks the cart with an optional label; every counter in the branch sees it and can resume it.
 *   Resuming takes the cart back to that counter: the parked row is then closed, and its sale (if it is
 *   finished) is linked by a "completed" event.
 * - Awaiting transfer parks the cart with its amount while a slow transfer alert comes in. Whoever sees
 *   the alert finishes it from any counter, and the receipt prints; one older than 30 minutes is flagged.
 * - A discard needs a reason. A cashier discards only their own, and only at or below the shop's limit;
 *   anything else needs a manager's PIN. Every discard is an event with its actor, for the dashboard.
 */
final readonly class Parked
{
    public const TRANSFER_FLAG_MINUTES = 30;

    private const MAX_PAYLOAD_BYTES = 65_536;

    public function __construct(private Settings $settings, private Approvals $approvals, private AuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $payload  the counter's cart, as it will resume it
     */
    public function park(Terminal $terminal, User $actor, string $kind, ?string $label, array $payload, int $amountKobo): string
    {
        $this->mayHold($actor);
        if (! in_array($kind, ['hold', 'awaiting_transfer'], true)) {
            throw new SaleRefused('A sale is held, or parked as Awaiting transfer.');
        }
        if ($kind === 'awaiting_transfer' && $amountKobo <= 0) {
            throw new SaleRefused('Enter the transfer amount.');
        }
        $json = json_encode((object) $payload, JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
            throw new SaleRefused('This cart is too large to hold. Finish it, or split it into two sales.');
        }
        $label = $label === null ? null : (trim($label) === '' ? null : mb_substr(trim($label), 0, 60));
        $now = CarbonImmutable::now()->format('Y-m-d H:i:s.uP');

        return DB::transaction(function () use ($terminal, $actor, $kind, $label, $json, $amountKobo, $now): string {
            $id = Ulid::generate();
            DB::table('parked_sales')->insert([
                'id' => $id, 'branch_id' => $terminal->branch_id, 'terminal_id' => $terminal->id, 'kind' => $kind,
                'label' => $label, 'payload' => $json, 'amount_kobo' => max(0, $amountKobo), 'status' => 'open',
                'parked_by' => $actor->id, 'parked_at' => $now,
            ]);
            DB::table('parked_sale_events')->insert(['id' => Ulid::generate(), 'parked_sale_id' => $id, 'type' => 'parked',
                'actor_id' => $actor->id, 'terminal_id' => $terminal->id, 'at' => $now]);

            return $id;
        });
    }

    /**
     * The branch's open parked sales, oldest first, for every counter's strip.
     *
     * @return list<array{id: string, kind: string, label: ?string, amount_kobo: int, parked_by: string, parked_by_name: string, terminal: string, parked_at: string, age_seconds: int, flagged: bool}>
     */
    public function open(Terminal $terminal): array
    {
        /** @var list<object{id: string, kind: string, label: ?string, amount_kobo: int, parked_by: string, parked_by_name: string, terminal: string, parked_at: string}> $rows */
        $rows = DB::table('parked_sales as p')->join('users as u', 'u.id', '=', 'p.parked_by')->join('terminals as t', 't.id', '=', 'p.terminal_id')
            ->where('p.branch_id', $terminal->branch_id)->where('p.status', 'open')->orderBy('p.parked_at')
            ->get(['p.id', 'p.kind', 'p.label', 'p.amount_kobo', 'p.parked_by', 'u.name as parked_by_name', 't.code as terminal', 'p.parked_at'])->all();
        $now = CarbonImmutable::now();

        return array_map(function (object $r) use ($now): array {
            $age = (int) max(0, CarbonImmutable::parse($r->parked_at)->diffInSeconds($now));

            return [
                'id' => $r->id, 'kind' => $r->kind, 'label' => $r->label, 'amount_kobo' => (int) $r->amount_kobo,
                'parked_by' => $r->parked_by, 'parked_by_name' => $r->parked_by_name, 'terminal' => $r->terminal,
                'parked_at' => $r->parked_at, 'age_seconds' => $age,
                'flagged' => $r->kind === 'awaiting_transfer' && $age > self::TRANSFER_FLAG_MINUTES * 60,
            ];
        }, $rows);
    }

    /**
     * Takes a held cart back to this counter. An Awaiting transfer cart stays parked until it is finished, so
     * any counter can still finish it; resuming one only hands over its cart.
     *
     * @return array{id: string, kind: string, label: ?string, amount_kobo: int, payload: array<string, mixed>}
     */
    public function resume(string $id, Terminal $terminal, User $actor): array
    {
        $this->mayHold($actor);

        return DB::transaction(function () use ($id, $terminal, $actor): array {
            /** @var object{id: string, kind: string, label: ?string, amount_kobo: int, payload: string, status: string}|null $row */
            $row = DB::table('parked_sales')->where('id', $id)->where('branch_id', $terminal->branch_id)->lockForUpdate()
                ->first(['id', 'kind', 'label', 'amount_kobo', 'payload', 'status']);
            if ($row === null || $row->status !== 'open') {
                throw new SaleRefused('That held sale was already resumed, finished or discarded on another counter.');
            }
            if ($row->kind === 'hold') {
                DB::table('parked_sales')->where('id', $id)->update(['status' => 'resumed']);
                DB::table('parked_sale_events')->insert(['id' => Ulid::generate(), 'parked_sale_id' => $id, 'type' => 'resumed',
                    'actor_id' => $actor->id, 'terminal_id' => $terminal->id, 'at' => CarbonImmutable::now()->format('Y-m-d H:i:s.uP')]);
            }
            /** @var array<string, mixed> $payload */
            $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);

            return ['id' => $row->id, 'kind' => $row->kind, 'label' => $row->label, 'amount_kobo' => (int) $row->amount_kobo, 'payload' => $payload];
        });
    }

    /** Discards a parked sale, with its reason, and the manager's approval where it is needed [F12]. */
    public function discard(string $id, Terminal $terminal, User $actor, string $reason, ?string $approvalToken): void
    {
        $this->mayHold($actor);
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        if (mb_strlen($reason) < 3) {
            throw new SaleRefused('Say why this sale is discarded.');
        }

        DB::transaction(function () use ($id, $terminal, $actor, $reason, $approvalToken): void {
            /** @var object{id: string, amount_kobo: int, parked_by: string, status: string, kind: string}|null $row */
            $row = DB::table('parked_sales')->where('id', $id)->where('branch_id', $terminal->branch_id)->lockForUpdate()
                ->first(['id', 'amount_kobo', 'parked_by', 'status', 'kind']);
            if ($row === null || $row->status !== 'open') {
                throw new SaleRefused('That held sale was already resumed, finished or discarded on another counter.');
            }

            $approvalId = null;
            if ($this->needsApproval($actor, $row->parked_by, (int) $row->amount_kobo)) {
                if ($approvalToken === null || $approvalToken === '') {
                    throw new ApprovalNeeded('A manager must approve this discard.', ApprovalAction::DiscardParkedSale, $id);
                }
                try {
                    $approvalId = $this->approvals->bind($terminal, $approvalToken, ApprovalAction::DiscardParkedSale, $id);
                } catch (ApprovalRefused $e) {
                    throw new SaleRefused($e->getMessage());
                }
            }

            DB::table('parked_sales')->where('id', $id)->update(['status' => 'discarded']);
            DB::table('parked_sale_events')->insert(['id' => Ulid::generate(), 'parked_sale_id' => $id, 'type' => 'discarded',
                'actor_id' => $actor->id, 'terminal_id' => $terminal->id, 'reason' => mb_substr($reason, 0, 200), 'approval_id' => $approvalId,
                'at' => CarbonImmutable::now()->format('Y-m-d H:i:s.uP')]);
            $this->audit->record(new AuditEntry(action: 'parked.discarded', actorId: $actor->id, terminalId: $terminal->id,
                subjectType: 'parked_sale', subjectId: $id, after: ['reason' => $reason, 'amount_kobo' => (int) $row->amount_kobo, 'kind' => $row->kind, 'approval_id' => $approvalId]));
        });
    }

    /** The shop's discard limit for a cashier, in kobo. Above it, or for someone else's sale, a manager approves. */
    public function limitKobo(): int
    {
        $limit = $this->settings->get('parked.discard_limit_kobo');

        return is_int($limit) ? $limit : 0;
    }

    private function needsApproval(User $actor, string $parkedBy, int $amountKobo): bool
    {
        if ($actor->role !== Role::Cashier) {
            return false;
        }

        return $parkedBy !== $actor->id || $amountKobo > $this->limitKobo();
    }

    private function mayHold(User $actor): void
    {
        if ($actor->role === Role::Storekeeper) {
            throw new SaleRefused('A storekeeper does not hold sales.');
        }
    }
}
