<?php

declare(strict_types=1);

namespace App\Catalogue\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The item_facets table (spec section 5).
 *
 * @property string $id
 * @property string $item_id
 * @property string $facet_key
 * @property string|null $picklist_value_id
 * @property string|null $text_value
 */
class ItemFacet extends Model
{
    use HasUlids;

    protected $table = 'item_facets';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [];
    }
}
