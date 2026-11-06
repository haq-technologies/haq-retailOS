<?php

declare(strict_types=1);

namespace App\Settings;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Organisation\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Reads and changes settings (spec section 11). A value is resolved from the branch, then the shop, then
 * the definition's default. Every change writes the setting, a settings_history row and an audit entry
 * in one transaction. A setting locked by the sysadmin can be changed only by the sysadmin.
 */
final class Settings
{
    /** Who may change a setting. */
    public const SOURCES = ['profile', 'console', 'owner', 'system'];

    public function __construct(
        private readonly Registry $registry,
        private readonly AuditLog $audit,
    ) {}

    /** The value in force for the local branch (or the given one). */
    public function get(string $key, ?string $branchId = null): mixed
    {
        $definition = $this->registry->get($key);
        $branchId ??= Branch::query()->where('is_local', true)->value('id');

        $rows = DB::table('settings')->where('key', $key)
            ->where(fn ($q) => $q->where('scope', 'shop')->orWhere(fn ($q) => $q->where('scope', 'branch')->where('scope_id', $branchId)))
            ->get(['scope', 'value']);

        foreach (['branch', 'shop'] as $scope) {
            $row = $rows->firstWhere('scope', $scope);
            if ($row !== null) {
                return json_decode((string) $row->value, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $definition->default;
    }

    public function isLocked(string $key, string $scope = 'shop', ?string $scopeId = null): bool
    {
        $this->registry->get($key);

        return (bool) DB::table('settings')->where(['key' => $key, 'scope' => $scope, 'scope_id' => $scopeId])->value('locked_by_sysadmin');
    }

    /**
     * Changes a setting. Returns false when the value and lock were already as asked, so applying the
     * same profile twice changes nothing.
     *
     * @param  string  $source  profile and console act as the sysadmin; owner and system may not touch a locked setting.
     */
    public function set(
        string $key,
        mixed $value,
        string $source,
        string $scope = 'shop',
        ?string $scopeId = null,
        ?bool $locked = null,
        ?string $actorId = null,
    ): bool {
        $definition = $this->registry->get($key);
        if (! in_array($scope, $definition->scopes, true)) {
            throw new InvalidSettingValue($key, ["It cannot be set for a {$scope}; it is set for: ".implode(', ', $definition->scopes).'.']);
        }
        if (! in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException("Unknown settings source {$source}.");
        }
        $validator = Validator::make(['value' => $value], ['value' => $definition->rules]);
        if ($validator->fails()) {
            throw new InvalidSettingValue($key, array_values($validator->errors()->all()));
        }

        $asSysadmin = in_array($source, ['profile', 'console'], true);

        return DB::transaction(function () use ($definition, $key, $value, $source, $scope, $scopeId, $locked, $actorId, $asSysadmin): bool {
            $current = DB::table('settings')->where(['key' => $key, 'scope' => $scope, 'scope_id' => $scopeId])->lockForUpdate()->first();
            $currentLocked = $current !== null && (bool) $current->locked_by_sysadmin;
            if ($currentLocked && ! $asSysadmin) {
                throw new SettingLocked($key);
            }
            if ($locked !== null && ! $asSysadmin) {
                throw new SettingLocked($key);
            }

            $newLocked = $locked ?? ($current === null ? $definition->lockedByDefault : $currentLocked);
            $oldValue = $current === null ? null : json_decode((string) $current->value, true, flags: JSON_THROW_ON_ERROR);
            if ($current !== null && $oldValue === $value && $currentLocked === $newLocked) {
                return false;
            }

            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $now = CarbonImmutable::now();
            if ($current === null) {
                DB::table('settings')->insert([
                    'id' => (string) Str::ulid(), 'scope' => $scope, 'scope_id' => $scopeId, 'key' => $key,
                    'value' => $json, 'locked_by_sysadmin' => $newLocked, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                DB::table('settings')->where('id', $current->id)->update(['value' => $json, 'locked_by_sysadmin' => $newLocked, 'updated_at' => $now]);
            }

            DB::table('settings_history')->insert([
                'id' => (string) Str::ulid(), 'scope' => $scope, 'scope_id' => $scopeId, 'key' => $key,
                'old_value' => $current === null ? null : $current->value, 'new_value' => $json,
                'locked_by_sysadmin' => $newLocked, 'changed_by' => $actorId, 'source' => $source, 'changed_at' => $now,
            ]);

            $shown = fn (mixed $v) => $definition->sensitive ? ['change' => 'value changed'] : ['value' => $v];
            $this->audit->record(new AuditEntry(
                action: 'setting.changed',
                actorId: $actorId,
                device: $source === 'profile' ? 'profile' : null,
                subjectType: 'setting',
                before: $current === null ? null : [...$shown($oldValue), 'key' => $key, 'scope' => $scope, 'locked' => $currentLocked],
                after: [...$shown($value), 'key' => $key, 'scope' => $scope, 'locked' => $newLocked, 'source' => $source],
                sensitiveBefore: $definition->sensitive && $current !== null ? ['value' => $oldValue] : null,
                sensitiveAfter: $definition->sensitive ? ['value' => $value] : null,
            ));

            return true;
        });
    }
}
