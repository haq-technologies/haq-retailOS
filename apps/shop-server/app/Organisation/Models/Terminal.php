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
 * @property string $id
 * @property string $branch_id
 * @property string $code
 * @property string $name
 * @property string $kind counter or back_office
 * @property string|null $printer_host
 * @property int|null $printer_port
 * @property CarbonImmutable|null $enrolled_at
 * @property CarbonImmutable|null $deactivated_at
 */
class Terminal extends Model
{
    use HasUlids;

    public const COUNTER = 'counter';

    public const BACK_OFFICE = 'back_office';

    protected $guarded = [];

    /** The device secret's hash never leaves the server. */
    protected $hidden = ['device_secret_hash'];

    /** The column's default, so a terminal made in code is a counter before it is reloaded. */
    protected $attributes = ['kind' => self::COUNTER];

    protected function casts(): array
    {
        return ['deactivated_at' => 'immutable_datetime', 'enrolled_at' => 'immutable_datetime'];
    }

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
