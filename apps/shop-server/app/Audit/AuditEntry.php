<?php

declare(strict_types=1);

namespace App\Audit;

use Carbon\CarbonImmutable;

/**
 * One action to record: who, what, when, which PC, and the before and after values (spec section 10).
 * Values that must stay with the owner (cost, credit limits) go in $sensitiveBefore and $sensitiveAfter;
 * the main row then carries only their hash and the redacted description in $before and $after.
 */
final readonly class AuditEntry
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>|null  $sensitiveBefore
     * @param  array<string, mixed>|null  $sensitiveAfter
     */
    public function __construct(
        public string $action,
        public ?string $actorId = null,
        public ?string $terminalId = null,
        public ?string $device = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?array $before = null,
        public ?array $after = null,
        public ?array $sensitiveBefore = null,
        public ?array $sensitiveAfter = null,
        public ?CarbonImmutable $recordedAt = null,
    ) {}

    public function hasSensitive(): bool
    {
        return $this->sensitiveBefore !== null || $this->sensitiveAfter !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'actor_id' => $this->actorId,
            'terminal_id' => $this->terminalId,
            'device' => $this->device,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'before' => $this->before,
            'after' => $this->after,
            'sensitive_before' => $this->sensitiveBefore,
            'sensitive_after' => $this->sensitiveAfter,
            'recorded_at' => $this->recordedAt === null ? null : Hasher::time($this->recordedAt),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $string = fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) ? $data[$key] : null;
        $array = function (string $key) use ($data): ?array {
            $value = $data[$key] ?? null;

            /** @var array<string, mixed>|null */
            return is_array($value) ? $value : null;
        };
        $recordedAt = $string('recorded_at');

        return new self(
            action: $string('action') ?? 'unknown',
            actorId: $string('actor_id'),
            terminalId: $string('terminal_id'),
            device: $string('device'),
            subjectType: $string('subject_type'),
            subjectId: $string('subject_id'),
            before: $array('before'),
            after: $array('after'),
            sensitiveBefore: $array('sensitive_before'),
            sensitiveAfter: $array('sensitive_after'),
            recordedAt: $recordedAt === null ? null : CarbonImmutable::parse($recordedAt),
        );
    }
}
