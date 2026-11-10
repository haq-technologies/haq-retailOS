<?php

declare(strict_types=1);

namespace App\Pilot;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Identity\Models\User;
use App\Platform\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Writing the measured pilot's records (Phase 5) [D56]: the owner starting the 14 days, the notebook's
 * totals, each difference traced and written down, a trace withdrawn, and the owner's decision. Every row
 * is new (the tables are append-only), every action is audited, and once the owner has decided the
 * record is closed, so the scorecard the decision rests on cannot change afterwards.
 */
final readonly class Records
{
    /** How far ahead the 14 days may be set to start. */
    public const START_WITHIN_DAYS = 60;

    public function __construct(private Measure $measure, private AuditLog $audit) {}

    /**
     * Starts the 14 days on a day from today on. Starting them again once they have begun needs a reason,
     * and never reaches back, so the days measured can never be chosen after the event.
     *
     * @throws PilotRefused
     */
    public function start(User $actor, string $firstDay, ?string $note): string
    {
        $note = self::clean($note);
        $today = $this->measure->today();
        $day = self::date($firstDay);
        if ($day < $today) {
            throw new PilotRefused('The 14 days start today or on a later day, never an earlier one.');
        }
        if ($day > CarbonImmutable::parse($today)->addDays(self::START_WITHIN_DAYS)->toDateString()) {
            throw new PilotRefused('Choose a first day within the next '.self::START_WITHIN_DAYS.' days.');
        }
        if ($this->measure->decision() !== null) {
            throw new PilotRefused('The owner has decided on the pilot, so it is over and cannot start again.');
        }
        $current = $this->measure->start();
        $begun = $current !== null && $current['first_day'] <= $today;
        if ($begun && $note === null) {
            throw new PilotRefused('The 14 days have begun. Say why they start again; the days measured so far stay on record.');
        }

        return DB::transaction(function () use ($actor, $day, $note, $current, $begun): string {
            $id = Ulid::generate();
            DB::table('pilot_events')->insert(['id' => $id, 'seq' => $this->seq('pilot_events'), 'kind' => 'started', 'first_day' => $day,
                'note' => $note, 'actor_id' => $actor->id, 'at' => self::now()]);
            $this->audit->record(new AuditEntry(action: $begun ? 'pilot.restarted' : 'pilot.started', actorId: $actor->id, subjectType: 'pilot', subjectId: $id,
                before: $current === null ? null : ['first_day' => $current['first_day']], after: ['first_day' => $day, 'note' => $note]));

            return $id;
        });
    }

    /**
     * The notebook's totals for a day, per payment method, all three at once. A correction is a new
     * entry, and the newest counts.
     *
     * @param  array<string, int>  $amounts  method => kobo
     *
     * @throws PilotRefused
     */
    public function notebook(User $actor, string $day, array $amounts): void
    {
        $day = $this->openDay($day);
        foreach (Measure::METHODS as $method) {
            if (! isset($amounts[$method]) || $amounts[$method] < 0 || $amounts[$method] > 100_000_000_000) {
                throw new PilotRefused('Type the notebook\'s total for cash, transfer and POS; type 0 for none.');
            }
        }
        $before = $this->measure->day($day);

        DB::transaction(function () use ($actor, $day, $amounts, $before): void {
            $now = self::now();
            foreach (Measure::METHODS as $method) {
                DB::table('notebook_totals')->insert(['id' => Ulid::generate(), 'seq' => $this->seq('notebook_totals'), 'business_day' => $day,
                    'method' => $method, 'amount_kobo' => $amounts[$method], 'entered_by' => $actor->id, 'entered_at' => $now]);
            }
            $was = array_combine(array_column($before['methods'], 'method'), array_column($before['methods'], 'notebook_kobo'));
            $this->audit->record(new AuditEntry(action: 'pilot.notebook_entered', actorId: $actor->id, subjectType: 'pilot',
                before: $before['notebook_kobo'] === null ? null : ['business_day' => $day, ...self::kobo($was)],
                after: ['business_day' => $day, ...self::kobo(array_intersect_key($amounts, array_flip(Measure::METHODS)))]));
        });
    }

    /**
     * Writes down how part of a day's difference happened: how much, whose record was wrong and what
     * happened, with the receipt number when one sale explains it. The amount takes the sign of what is
     * left to trace, and may not be more than that.
     *
     * @throws PilotRefused
     */
    public function trace(User $actor, string $day, string $method, int $amountKobo, string $side, string $what, ?string $receipt): string
    {
        $day = $this->openDay($day);
        if (! in_array($method, Measure::METHODS, true) || ! in_array($side, ['notebook', 'app'], true)) {
            throw new PilotRefused('Choose the payment method and whose record was wrong.');
        }
        $what = self::clean($what);
        if ($what === null || mb_strlen($what) < 3) {
            throw new PilotRefused('Write down what happened, in a few words.');
        }
        $receipt = self::clean($receipt);
        if ($receipt !== null) {
            $receipt = strtoupper($receipt);
            if (! DB::table('sales')->where('receipt_number', $receipt)->exists()) {
                throw new PilotRefused("No sale has the receipt number {$receipt}. Check it on the receipt, or leave it out.");
            }
        }

        return DB::transaction(function () use ($actor, $day, $method, $amountKobo, $side, $what, $receipt): string {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['pilot:'.$day]);
            $row = array_values(array_filter($this->measure->day($day)['methods'], fn (array $m) => $m['method'] === $method))[0];
            if ($row['left_kobo'] === null) {
                throw new PilotRefused('Enter the notebook\'s totals for the day first.');
            }
            if ($row['left_kobo'] === 0) {
                throw new PilotRefused('Nothing is left to trace there.');
            }
            if ($amountKobo <= 0 || $amountKobo > abs($row['left_kobo'])) {
                throw new PilotRefused('The amount must be more than nothing and no more than the difference left to trace.');
            }
            $signed = $row['left_kobo'] > 0 ? $amountKobo : -$amountKobo;
            $id = Ulid::generate();
            DB::table('pilot_traces')->insert(['id' => $id, 'seq' => $this->seq('pilot_traces'), 'business_day' => $day, 'method' => $method,
                'amount_kobo' => $signed, 'side' => $side, 'what' => $what, 'receipt_number' => $receipt, 'entered_by' => $actor->id, 'entered_at' => self::now()]);
            $this->audit->record(new AuditEntry(action: 'pilot.traced', actorId: $actor->id, subjectType: 'pilot', subjectId: $id,
                after: ['business_day' => $day, 'method' => $method, 'amount_kobo' => $signed, 'side' => $side, 'what' => $what, 'receipt_number' => $receipt]));

            return $id;
        });
    }

    /**
     * Withdraws a trace written by mistake, with a new row that names it; the trace stays on record.
     *
     * @throws PilotRefused
     */
    public function withdraw(User $actor, string $traceId): void
    {
        /** @var object{id: string, business_day: string, method: string, amount_kobo: int|string, side: string, what: string, receipt_number: ?string, withdraws_id: ?string}|null $trace */
        $trace = DB::table('pilot_traces')->where('id', $traceId)->first();
        if ($trace === null || $trace->withdraws_id !== null) {
            throw new PilotRefused('That trace is not known. Refresh the page.');
        }
        $this->openDay($trace->business_day);

        DB::transaction(function () use ($actor, $trace): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['pilot:'.$trace->business_day]);
            if (DB::table('pilot_traces')->where('withdraws_id', $trace->id)->exists()) {
                throw new PilotRefused('That trace was already withdrawn.');
            }
            DB::table('pilot_traces')->insert(['id' => Ulid::generate(), 'seq' => $this->seq('pilot_traces'), 'business_day' => $trace->business_day,
                'method' => $trace->method, 'amount_kobo' => $trace->amount_kobo, 'side' => $trace->side, 'what' => $trace->what, 'receipt_number' => $trace->receipt_number,
                'withdraws_id' => $trace->id, 'entered_by' => $actor->id, 'entered_at' => self::now()]);
            $this->audit->record(new AuditEntry(action: 'pilot.trace_withdrawn', actorId: $actor->id, subjectType: 'pilot', subjectId: $trace->id,
                before: ['business_day' => $trace->business_day, 'method' => $trace->method, 'amount_kobo' => (int) $trace->amount_kobo, 'side' => $trace->side, 'what' => $trace->what]));
        });
    }

    /**
     * The owner's decision at gate G5, continue or stop, with the scorecard as it stood. Changing it
     * later needs a reason, and both stay on record.
     *
     * @throws PilotRefused
     */
    public function decide(User $actor, string $decision, ?string $note): string
    {
        if (! in_array($decision, ['continue', 'stop'], true)) {
            throw new PilotRefused('Choose continue or stop.');
        }
        $note = self::clean($note);
        if ($this->measure->start() === null) {
            throw new PilotRefused('Start the 14 days first; the decision rests on them.');
        }
        $before = $this->measure->decision();
        if ($before !== null && $note === null) {
            throw new PilotRefused('The decision was recorded on '.CarbonImmutable::parse($before['at'])->setTimezone($this->measure->zone())->format('j F Y').'. Say why it changes.');
        }
        $scorecard = $this->measure->scorecard();
        unset($scorecard['decision']);

        return DB::transaction(function () use ($actor, $decision, $note, $scorecard, $before): string {
            $id = Ulid::generate();
            DB::table('pilot_events')->insert(['id' => $id, 'seq' => $this->seq('pilot_events'), 'kind' => 'decided', 'decision' => $decision, 'note' => $note,
                'scorecard' => json_encode($scorecard, JSON_THROW_ON_ERROR), 'actor_id' => $actor->id, 'at' => self::now()]);
            $this->audit->record(new AuditEntry(action: 'pilot.decided', actorId: $actor->id, subjectType: 'pilot', subjectId: $id,
                before: $before === null ? null : ['decision' => $before['decision']],
                after: ['decision' => $decision, 'note' => $note, 'measured' => $scorecard['measured'] ?? 0, 'passed' => $scorecard['passed'] ?? 0]));

            return $id;
        });
    }

    /**
     * A day the notebook and traces may still be written for: from the first day of the 14 to today,
     * while the owner has not decided.
     *
     * @throws PilotRefused
     */
    private function openDay(string $day): string
    {
        $day = self::date($day);
        $start = $this->measure->start();
        if ($start === null) {
            throw new PilotRefused('The owner has not started the 14 days yet.');
        }
        if ($this->measure->decision() !== null) {
            throw new PilotRefused('The owner has decided, so the pilot\'s record is closed.');
        }
        if ($day < $start['first_day'] || $day > $this->measure->today()) {
            throw new PilotRefused('That day is not one of the pilot\'s days.');
        }

        return $day;
    }

    /** @throws PilotRefused */
    private static function date(string $day): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || ! checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) {
            throw new PilotRefused('Choose a day.');
        }

        return $day;
    }

    private static function clean(?string $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        return $text === '' ? null : mb_substr($text, 0, 200);
    }

    /**
     * @param  array<string, int|null>  $amounts
     * @return array<string, int|null>
     */
    private static function kobo(array $amounts): array
    {
        $out = [];
        foreach ($amounts as $method => $kobo) {
            $out["{$method}_kobo"] = $kobo;
        }

        return $out;
    }

    /** The next seq of a table, under a lock held until the transaction commits. */
    private function seq(string $table): int
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['seq:'.$table]);
        $max = DB::table($table)->max('seq');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }

    private static function now(): string
    {
        return CarbonImmutable::now()->format('Y-m-d H:i:s.uP');
    }
}
