<?php

declare(strict_types=1);

namespace App\Identity\Models;

use App\Identity\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A person at the shop. Deactivated, never deleted [F18]. The PIN hash never leaves the model:
 * pages get explicit arrays.
 *
 * @property string $id
 * @property string $name
 * @property Role $role
 * @property string|null $pin_hash
 * @property CarbonImmutable|null $pin_changed_at
 * @property CarbonImmutable|null $deactivated_at
 */
class User extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'pin_changed_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deactivated_at');
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }
}
