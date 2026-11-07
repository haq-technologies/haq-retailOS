<?php

declare(strict_types=1);

namespace App\BackOffice\Http;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Cost\CostAccess;
use App\Platform\Ulid;
use App\Reports\Csv;
use App\Reports\Flags;
use App\Reports\Period;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The flag list, its CSV export and marking flags as seen (spec sections 8 and 12; wireframe FlagList). The
 * owner and managers see it; cost amounts only for those who may see cost [F10].
 *
 * @phpstan-import-type Flag from Flags
 */
final class FlagsController
{
    use ActsForUser;

    public function index(Request $request, Flags $flags, CostAccess $cost): JsonResponse
    {
        $actor = $this->allowed($request, 'flags');
        [$period, $kinds, $cashier] = $this->filters($request, $flags);
        $all = $flags->list($period, $cost->canSee($actor), null, $cashier);
        $shown = $this->only($all, $kinds);
        $limit = $request->integer('limit', 50);

        return response()->json([
            'period' => $period->name,
            'kinds' => Flags::KINDS,
            'counts' => Flags::counts($all),
            'total' => count($shown),
            'flags' => array_slice($shown, 0, max(1, min(1000, $limit))),
            'people' => $this->people($flags, $period),
        ]);
    }

    public function csv(Request $request, Flags $flags, CostAccess $cost): Response
    {
        $actor = $this->allowed($request, 'flags');
        $withCost = $cost->canSee($actor);
        [$period, $kinds, $cashier] = $this->filters($request, $flags);
        $rows = $this->only($flags->list($period, $withCost, null, $cashier), $kinds);
        $zone = $flags->zone();

        $header = ['When', 'Flag', 'What happened', 'Detail', 'Amount (NGN)', ...($withCost ? ['Cost (NGN)'] : []), 'Who', 'Approved by', 'Counter', 'Receipt', 'Seen by', 'Seen at'];
        $lines = array_map(fn (array $f) => [
            CarbonImmutable::parse($f['at'])->setTimezone($zone)->format('Y-m-d H:i'), $f['label'], $f['title'], $f['detail'],
            Csv::naira($f['amount_kobo']), ...($withCost ? [Csv::naira($f['cost_kobo'] ?? null)] : []),
            $f['who'], $f['approver'], $f['terminal'], $f['receipt_number'], $f['seen_by'],
            $f['seen_at'] === null ? null : CarbonImmutable::parse($f['seen_at'])->setTimezone($zone)->format('Y-m-d H:i'),
        ], $rows);

        return Csv::download('flags-'.$period->name.'-'.CarbonImmutable::now($zone)->format('Y-m-d').'.csv', $header, $lines);
    }

    /** Marks flags as seen. Each flag keeps its first mark; a flag is never deleted. */
    public function seen(Request $request, AuditLog $audit): JsonResponse
    {
        $actor = $this->allowed($request, 'flags');
        $data = $request->validate(['keys' => ['required', 'array', 'min:1', 'max:500'], 'keys.*' => ['required', 'string', 'max:80']]);
        /** @var list<string> $keys */
        $keys = array_values(array_unique(array_map('strval', (array) $data['keys'])));
        foreach ($keys as $key) {
            if (! Flags::exists($key)) {
                return response()->json(['message' => 'That flag is not known. Refresh the list.'], 422);
            }
        }

        $marked = DB::transaction(function () use ($keys, $actor, $audit): array {
            /** @var list<string> $already */
            $already = DB::table('flag_marks')->whereIn('flag_key', $keys)->pluck('flag_key')->all();
            $new = array_values(array_diff($keys, $already));
            $now = CarbonImmutable::now()->format('Y-m-d H:i:s.uP');
            foreach ($new as $key) {
                DB::table('flag_marks')->insert(['id' => Ulid::generate(), 'flag_key' => $key, 'seen_by' => $actor->id, 'seen_at' => $now]);
            }
            if ($new !== []) {
                $audit->record(new AuditEntry(action: 'flag.seen', actorId: $actor->id, subjectType: 'flag', after: ['flags' => $new]));
            }

            return $new;
        });

        return response()->json(['marked' => count($marked)]);
    }

    /**
     * @return array{0: Period, 1: ?list<string>, 2: ?string}
     */
    private function filters(Request $request, Flags $flags): array
    {
        $name = $request->query('period');
        $period = Period::named(is_string($name) && in_array($name, Period::NAMES, true) ? $name : 'week', $flags->zone());
        $kind = $request->query('kind');
        $kinds = is_string($kind) && $kind !== '' ? array_values(array_intersect(explode(',', $kind), array_keys(Flags::KINDS))) : null;
        $person = $request->query('person');

        return [$period, $kinds, is_string($person) && Ulid::isValid($person) ? $person : null];
    }

    /**
     * @param  list<Flag>  $flags
     * @param  ?list<string>  $kinds
     * @return list<Flag>
     */
    private function only(array $flags, ?array $kinds): array
    {
        return $kinds === null ? $flags : array_values(array_filter($flags, fn (array $f) => in_array($f['kind'], $kinds, true)));
    }

    /**
     * Everyone with a flag in the period, for the person filter.
     *
     * @return list<array{id: string, name: string}>
     */
    private function people(Flags $flags, Period $period): array
    {
        $ids = array_values(array_unique(array_filter(array_column($flags->list($period, false), 'cashier_id'))));

        /** @var list<array{id: string, name: string}> */
        return $ids === [] ? [] : DB::table('users')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn (object $u) => ['id' => (string) $u->id, 'name' => (string) $u->name])->all();
    }
}
