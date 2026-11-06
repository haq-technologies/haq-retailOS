<?php

declare(strict_types=1);

namespace App\Catalogue\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The categories table (spec section 5).
 *
 * @property string $id
 * @property string $code
 * @property string|null $parent_id
 * @property string $name
 * @property string|null $word
 * @property bool $sellable
 * @property list<string> $aliases
 * @property int $position
 */
class Category extends Model
{
    use HasUlids;

    protected $table = 'categories';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['aliases' => 'array', 'sellable' => 'boolean'];
    }
}
