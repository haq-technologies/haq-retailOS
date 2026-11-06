<?php

declare(strict_types=1);

namespace App\Organisation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The shop: one owner, one or more branches (spec section 1).
 *
 * @property string $id
 * @property string $name
 */
class Tenant extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}
