<?php

declare(strict_types=1);

namespace App\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reading the audit trail (spec sections 3 and 10; check D5) [F10, D47]. Who reads what is the caller's
 * to decide from the roles table: a cashier reads only their own actions, a manager the branch's, both
 * redacted; only the owner reads the sensitive values (cost, credit limits), which never leave
 * audit_sensitive otherwise. Read-only: nothing here can change or delete an entry.
 */
final class History
{
    /** Plain names for the actions the shop sees most; any other is shown from its code. */
    private const NAMES = [
        'sale.finished' => 'Sale finished',
        'sale.refused' => 'Sale refused',
        'sale.failed' => 'Sale failed',
        'sale.back_entered' => 'Sale re-entered after a restore',
        'parked.discarded' => 'Held sale discarded',
        'drawer.no_sale_open' => 'Drawer opened, no sale',
        'drawer.key_open' => 'Drawer opened with the key',
        'print.failed' => 'Receipt did not print',
        'print.reprint_requested' => 'Receipt reprinted',
        'price.set' => 'Set price changed',
        'price.undone' => 'Set price undone',
        'cost.entered' => 'Cost entered',
        'identity.signed_in' => 'Signed in',
        'identity.sign_in_failed' => 'Wrong PIN',
        'identity.locked_out' => 'Locked out after wrong PINs',
        'identity.approval_granted' => 'Manager approval given',
        'identity.approval_bound' => 'Manager approval used',
        'identity.approval_failed' => 'Manager approval refused',
        'identity.user_added' => 'Person added',
        'identity.user_deactivated' => 'Person deactivated',
        'identity.pin_changed' => 'PIN changed',
        'identity.terminal_enrolled' => 'Device enrolled',
        'catalogue.item_created' => 'Item created',
        'catalogue.item_changed' => 'Item changed',
        'catalogue.item_approved' => 'Item approved',
        'catalogue.item_merged' => 'Items merged',
        'catalogue.item_merge_undone' => 'Merge undone',
        'catalogue.barcode_learned' => 'Barcode learned',
        'catalogue.barcode_undone' => 'Barcode link undone',
        'catalogue.brand_typed' => 'Brand typed',
        'catalogue.brand_approved' => 'Brand approved',
        'catalogue.brand_merged' => 'Brand merged',
        'catalogue.category_created' => 'Category created',
        'shift.opened' => 'Shift opened',
        'shift.opened_unattributed' => 'Shift opened by a sale',
        'shift.auto_closed' => 'Shift closed by itself',
        'setting.changed' => 'Setting changed',
        'summary.printed' => 'Daily summary printed',
        'flag.seen' => 'Flag marked as seen',
        'backup.requested' => 'Backup asked for',
        'clock.confirmed' => 'Server time confirmed',
        'recovery.journal_reported' => 'Counter journal reported',
    ];

    /**
     * The entries newest first, a page at a time.
     *
     * @param  ?string  $actorId  only this person's actions (a cashier's own history)
     * @param  bool  $sensitive  with the sensitive values (the owner only)
     * @param  ?string  $before  the cursor from the previous page
     * @return array{entries: list<array<string, mixed>>, next: ?string}
     */
    public static function page(?string $actorId, bool $sensitive, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null, ?string $before = null, int $limit = 50): array
    {
        $query = DB::table('audit_log as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->leftJoin('terminals as t', 't.id', '=', 'a.terminal_id')
            ->select(['a.id', 'a.recorded_at', 'a.action', 'a.subject_type', 'a.subject_id', 'a.redacted_before', 'a.redacted_after',
                'a.sensitive_hash', 'a.device', 'u.name as actor', 't.code as terminal', 't.name as terminal_name'])
            ->orderByDesc('a.recorded_at')->orderByDesc('a.id')->limit($limit + 1);
        if ($actorId !== null) {
            $query->where('a.actor_id', $actorId);
        }
        if ($from !== null) {
            $query->where('a.recorded_at', '>=', $from->format('Y-m-d H:i:s.uP'));
        }
        if ($to !== null) {
            $query->where('a.recorded_at', '<', $to->format('Y-m-d H:i:s.uP'));
        }
        if ($before !== null && preg_match('/^(\d{4}-\d{2}-\d{2}T[0-9:.]+Z)\|([0-9a-z]{26})$/', $before, $m) === 1) {
            $at = CarbonImmutable::parse($m[1])->format('Y-m-d H:i:s.uP');
            $query->where(fn ($q) => $q->where('a.recorded_at', '<', $at)->orWhere(fn ($q) => $q->where('a.recorded_at', $at)->where('a.id', '<', $m[2])));
        }
        $rows = $query->get()->all();
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $secrets = [];
        if ($sensitive) {
            $ids = array_values(array_map(fn (object $r) => (string) $r->id, array_filter($rows, fn (object $r) => $r->sensitive_hash !== null)));
            foreach ($ids === [] ? [] : DB::table('audit_sensitive')->whereIn('audit_id', $ids)->get(['audit_id', 'before', 'after']) as $s) {
                $secrets[(string) $s->audit_id] = ['before' => self::decode($s->before), 'after' => self::decode($s->after)];
            }
        }

        $entries = array_map(function (object $r) use ($secrets, $sensitive): array {
            $entry = [
                'id' => (string) $r->id,
                'at' => CarbonImmutable::parse((string) $r->recorded_at)->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'action' => (string) $r->action,
                'what' => self::NAMES[(string) $r->action] ?? ucfirst(str_replace(['.', '_'], [': ', ' '], (string) $r->action)),
                'who' => is_string($r->actor) ? $r->actor : null,
                'device' => is_string($r->terminal_name) ? $r->terminal_name : (is_string($r->device) ? $r->device : null),
                'subject' => is_string($r->subject_type) ? $r->subject_type : null,
                'before' => self::decode($r->redacted_before),
                'after' => self::decode($r->redacted_after),
                // The owner's eyes only; for anyone else the entry says a value is held back.
                'held_back' => $r->sensitive_hash !== null && ! $sensitive,
            ];
            if ($sensitive && isset($secrets[$entry['id']])) {
                $entry['sensitive'] = $secrets[$entry['id']];
            }

            return $entry;
        }, $rows);

        $last = end($entries);

        return ['entries' => $entries, 'next' => $more && $last !== false ? $last['at'].'|'.$last['id'] : null];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(mixed $json): ?array
    {
        if (! is_string($json)) {
            return null;
        }
        $value = json_decode($json, true);

        /** @var array<string, mixed>|null */
        return is_array($value) ? $value : null;
    }
}
