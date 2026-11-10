<?php

declare(strict_types=1);

namespace App\Shifts;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Organisation\Models\Terminal;
use App\Platform\Ulid;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Shifts per drawer (spec section 9) [F19, F20, S11, S12]. The pilot needs the part a sale depends on: a
 * sale always lands on its drawer's open shift, and the shift sets the business day. A sale with no open
 * shift is never blocked: the counter opens an unattributed shift with no counted float, which the owner
 * dashboard flags. A shift still open four hours after closing time closes as unclosed. Counted closes,
 * handovers and the Z-report come with the MVP.
 */
final readonly class Shifts
{
    public function __construct(private Settings $settings, private AuditLog $audit) {}

    /**
     * The drawer's open shift, opening one if there is none. Call inside the sale's transaction.
     *
     * @return object{id: string, business_day: string, drawer_id: string}
     */
    public function openFor(Terminal $terminal, ?string $actorId, ?int $openingFloatKobo = null): object
    {
        $drawerId = $this->drawerFor($terminal);
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['shift:'.$drawerId]);

        /** @var object{id: string, business_day: string, drawer_id: string}|null $open */
        $open = DB::table('shifts')->where('drawer_id', $drawerId)->where('status', 'open')->first(['id', 'business_day', 'drawer_id']);
        if ($open !== null) {
            return $open;
        }

        $now = CarbonImmutable::now();
        $id = Ulid::generate();
        $businessDay = $now->setTimezone($this->timezone())->toDateString();
        $unattributed = $openingFloatKobo === null;
        DB::table('shifts')->insert([
            'id' => $id, 'branch_id' => $terminal->branch_id, 'drawer_id' => $drawerId,
            'opened_by' => $actorId, 'opening_float_kobo' => $openingFloatKobo, 'business_day' => $businessDay,
            'status' => 'open', 'unattributed' => $unattributed, 'opened_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
        DB::table('shift_events')->insert([
            'id' => Ulid::generate(), 'shift_id' => $id, 'type' => 'open', 'cashier_id' => $actorId,
            'counted_cash_kobo' => $openingFloatKobo, 'at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
        $this->audit->record(new AuditEntry(action: $unattributed ? 'shift.opened_unattributed' : 'shift.opened', actorId: $actorId,
            terminalId: $terminal->id, subjectType: 'shift', subjectId: $id, after: ['business_day' => $businessDay, 'opening_float_kobo' => $openingFloatKobo]));

        return (object) ['id' => $id, 'business_day' => $businessDay, 'drawer_id' => $drawerId];
    }

    /**
     * The shift a back entry belongs to (spec section 9) [F1]: the drawer's newest shift on the sale's
     * business day, open or not, which a restore usually keeps; otherwise the drawer's open shift, as for
     * any sale. The back entry carries its own business day either way. Call inside a transaction.
     */
    public function forBackEntry(Terminal $terminal, string $businessDay, ?string $actorId): string
    {
        $drawerId = $this->drawerFor($terminal);
        $id = DB::table('shifts')->where('drawer_id', $drawerId)->where('business_day', $businessDay)->orderByDesc('opened_at')->value('id');

        return is_string($id) ? $id : $this->openFor($terminal, $actorId)->id;
    }

    /**
     * Closes every shift still open four hours after the shop's closing time, as unclosed and not
     * counted [S12]. With no closing time set, nothing closes by itself. Returns how many closed.
     */
    public function autoClose(): int
    {
        $closing = $this->settings->get('shifts.closing_time');
        if (! is_string($closing) || preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $closing) !== 1) {
            return 0;
        }
        $zone = $this->timezone();
        $now = CarbonImmutable::now();
        $closed = 0;

        foreach (DB::table('shifts')->where('status', 'open')->get(['id', 'business_day', 'drawer_id']) as $shift) {
            $deadline = CarbonImmutable::parse("{$shift->business_day} {$closing}", $zone)->addHours(4);
            if ($deadline->isFuture()) {
                continue;
            }
            DB::transaction(function () use ($shift, $now, &$closed): void {
                $updated = DB::table('shifts')->where('id', $shift->id)->where('status', 'open')
                    ->update(['status' => 'auto_closed', 'closed_at' => $now->format('Y-m-d H:i:s.uP')]);
                if ($updated === 0) {
                    return;
                }
                DB::table('shift_events')->insert(['id' => Ulid::generate(), 'shift_id' => $shift->id, 'type' => 'auto_close', 'at' => $now->format('Y-m-d H:i:s.uP')]);
                $this->audit->record(new AuditEntry(action: 'shift.auto_closed', subjectType: 'shift', subjectId: $shift->id,
                    after: ['business_day' => $shift->business_day, 'counted' => false]));
                $closed++;
            });
        }

        return $closed;
    }

    /**
     * The counter's open shift, if any.
     *
     * @return object{id: string, business_day: string, opening_float_kobo: ?int, unattributed: bool, opened_at: string}|null
     */
    public function current(Terminal $terminal): ?object
    {
        /** @var object{id: string, business_day: string, opening_float_kobo: ?int, unattributed: bool, opened_at: string}|null $shift */
        $shift = DB::table('shifts')->join('drawers', 'drawers.id', '=', 'shifts.drawer_id')
            ->where('drawers.terminal_id', $terminal->id)->where('shifts.status', 'open')
            ->first(['shifts.id', 'shifts.business_day', 'shifts.opening_float_kobo', 'shifts.unattributed', 'shifts.opened_at']);

        return $shift;
    }

    /** A counter's drawer row, fitted or not; a counter with no fitted drawer still has one [F19, S21]. */
    public function drawerFor(Terminal $terminal): string
    {
        $id = DB::table('drawers')->where('terminal_id', $terminal->id)->value('id');
        if (is_string($id)) {
            return $id;
        }
        $id = Ulid::generate();
        DB::table('drawers')->insert(['id' => $id, 'branch_id' => $terminal->branch_id, 'terminal_id' => $terminal->id,
            'label' => "{$terminal->code} drawer", 'fitted' => false, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function timezone(): string
    {
        $zone = $this->settings->get('business.timezone');

        return is_string($zone) ? $zone : 'Africa/Lagos';
    }
}
