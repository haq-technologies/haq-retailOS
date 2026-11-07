<?php

declare(strict_types=1);

namespace App\Reports;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A span of time for a report, cut on the shop's local days (spec section 4: times are UTC, the shop's day
 * is local). From is inclusive, to is exclusive; both are UTC.
 */
final readonly class Period
{
    public const NAMES = ['today', 'week', 'month', 'last7', 'all'];

    public function __construct(public CarbonImmutable $from, public CarbonImmutable $to, public string $name) {}

    public static function named(string $name, string $zone, ?CarbonImmutable $now = null): self
    {
        $local = ($now ?? CarbonImmutable::now())->setTimezone($zone);
        $end = $local->startOfDay()->addDay()->utc();

        return match ($name) {
            'today' => new self($local->startOfDay()->utc(), $end, 'today'),
            'week' => new self($local->startOfWeek(CarbonInterface::MONDAY)->utc(), $end, 'week'),
            'month' => new self($local->startOfMonth()->utc(), $end, 'month'),
            'last7' => new self($local->startOfDay()->subDays(6)->utc(), $end, 'last7'),
            default => new self(CarbonImmutable::create(2000, 1, 1, 0, 0, 0, 'UTC') ?? $local->subYears(30), $end, 'all'),
        };
    }

    /** One business day, as the hours of that local date. */
    public static function day(string $day, string $zone): self
    {
        $start = CarbonImmutable::parse($day, $zone)->startOfDay();

        return new self($start->utc(), $start->addDay()->utc(), 'day');
    }

    public function fromSql(): string
    {
        return $this->from->format('Y-m-d H:i:s.uP');
    }

    public function toSql(): string
    {
        return $this->to->format('Y-m-d H:i:s.uP');
    }

    public function contains(CarbonImmutable $at): bool
    {
        return $at->greaterThanOrEqualTo($this->from) && $at->lessThan($this->to);
    }
}
