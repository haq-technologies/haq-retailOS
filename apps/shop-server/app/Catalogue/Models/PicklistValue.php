<?php

declare(strict_types=1);

namespace App\Catalogue\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The picklist_values table (spec section 5).
 *
 * @property string $id
 * @property string|null $seed_id
 * @property string $list
 * @property string $name
 * @property list<string> $aliases
 * @property string|null $parent_id
 * @property string $status
 * @property int $position
 */
class PicklistValue extends Model
{
    use HasUlids;

    protected $table = 'picklist_values';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['aliases' => 'array'];
    }
}
