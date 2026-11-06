<?php

declare(strict_types=1);

namespace App\Catalogue\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The category_templates table (spec section 5).
 *
 * @property string $id
 * @property string $category_id
 * @property list<array{key: string, label: string, type: string, list?: string}> $facets
 * @property list<string> $drill_order
 * @property list<string> $name_order
 * @property array{combine: list<string>, blank_allowed: list<string>, exclude: array<string, list<string>>}|null $generator
 * @property bool $asks_colour
 */
class CategoryTemplate extends Model
{
    use HasUlids;

    protected $table = 'category_templates';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['facets' => 'array', 'drill_order' => 'array', 'name_order' => 'array', 'generator' => 'array', 'asks_colour' => 'boolean'];
    }
}
