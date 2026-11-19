<?php

declare(strict_types=1);

namespace App\Catalogue\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The brands table (spec section 5).
 *
 * @property string $id
 * @property string|null $seed_id
 * @property string $name
 * @property list<string> $aliases
 * @property list<string> $shown_first_in The category codes it is shown first in (D94)
 * @property string $status
 */
class Brand extends Model
{
    use HasUlids;

    protected $table = 'brands';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['aliases' => 'array', 'shown_first_in' => 'array'];
    }
}
