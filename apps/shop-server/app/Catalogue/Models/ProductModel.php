<?php

declare(strict_types=1);

namespace App\Catalogue\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The product_models table (spec section 5).
 *
 * @property string $id
 * @property string|null $seed_id
 * @property string|null $brand_id
 * @property string $name
 * @property list<string> $categories
 */
class ProductModel extends Model
{
    use HasUlids;

    protected $table = 'product_models';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['categories' => 'array'];
    }
}
