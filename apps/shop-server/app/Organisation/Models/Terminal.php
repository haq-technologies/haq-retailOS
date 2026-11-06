<?php

declare(strict_types=1);

namespace App\Organisation\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A counter PC (code T01), whose code starts every receipt number it issues, or a back-office device
 * (code D01), such as the owner's phone, which signs in the same way but never sells (D29). Each has
 * its own audit chain.
 *
 * @property string $kind counter or back_office
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

    public const COUNTER = 'counter';

    public const BACK_OFFICE = 'back_office';

    /** Only a counter sells; Finish refuses a sale from a back-office device (D29). */
    public function canSell(): bool
    {
        return $this->kind === self::COUNTER;
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
