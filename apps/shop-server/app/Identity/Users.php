<?php

declare(strict_types=1);

namespace App\Identity;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Adds people, sets their PINs and deactivates them. Nobody is ever deleted, and the last active owner
 * cannot be deactivated (spec section 3) [F18]. Every change is audited.
 */
final readonly class Users
{
    public function __construct(
        private Pins $pins,
        private AuditLog $audit,
    ) {}

    public function add(string $name, Role $role, string $pin, ?string $actorId = null): User
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 60) {
            throw new \InvalidArgumentException('A name tile needs a name of 1 to 60 characters.');
        }
        $this->pins->assertValid($pin, $role);

        return DB::transaction(function () use ($name, $role, $pin, $actorId): User {
            $user = User::query()->create([
                'name' => $name,
                'role' => $role,
                'pin_hash' => $this->pins->hash($pin),
                'pin_changed_at' => CarbonImmutable::now(),
            ]);
            $this->audit->record(new AuditEntry(action: 'identity.user_added', actorId: $actorId, subjectType: 'user', subjectId: $user->id,
                after: ['name' => $name, 'role' => $role->value]));

            return $user;
        });
    }

    public function setPin(User $user, string $pin, ?string $actorId = null): void
    {
        $this->pins->assertValid($pin, $user->role);
        DB::transaction(function () use ($user, $pin, $actorId): void {
            $user->update(['pin_hash' => $this->pins->hash($pin), 'pin_changed_at' => CarbonImmutable::now()]);
            $this->audit->record(new AuditEntry(action: 'identity.pin_changed', actorId: $actorId, subjectType: 'user', subjectId: $user->id));
        });
    }

    public function deactivate(User $user, ?string $actorId = null): void
    {
        DB::transaction(function () use ($user, $actorId): void {
            // Lock the active owners, so two deactivations at once cannot remove the last one.
            $owners = User::query()->active()->where('role', Role::Owner->value)->lockForUpdate()->pluck('id');
            if ($user->role === Role::Owner && $owners->count() <= 1 && $owners->contains($user->id)) {
                throw new RuntimeException('This is the last active owner, so they cannot be deactivated. Add another owner first.');
            }
            if (! $user->isActive()) {
                return;
            }
            $user->update(['deactivated_at' => CarbonImmutable::now(), 'deactivated_by' => $actorId]);
            $this->audit->record(new AuditEntry(action: 'identity.user_deactivated', actorId: $actorId, subjectType: 'user', subjectId: $user->id,
                before: ['active' => true], after: ['active' => false]));
        });
    }
}
