<?php

declare(strict_types=1);

namespace App\Organisation\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A counter PC. Its code (T01) starts every receipt number it issues, and it has its own audit chain.
 *
 * @property string $id
 * @property string $branch_id
 * @property string $code
 * @property string $name
 * @property CarbonImmutable|null $deactivated_at
 */
class Terminal extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['deactivated_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
