<?php

declare(strict_types=1);

namespace App\Catalogue\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The items table (spec section 5).
 *
 * @property string $id
 * @property string $category_id
 * @property string|null $brand_id
 * @property string|null $brand_text
 * @property string|null $free_text
 * @property string|null $typed_category_word
 * @property string $item_code
 * @property string $display_name
 * @property string $search_text
 * @property string $identity_key
 * @property string $status
 * @property string $source
 */
class Item extends Model
{
    use HasUlids;

    protected $table = 'items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }
}
