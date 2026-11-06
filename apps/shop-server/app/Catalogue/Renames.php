<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Catalogue\Models\Brand;
use App\Catalogue\Models\Category;
use App\Catalogue\Models\Item;
use App\Catalogue\Models\PicklistValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Renames that change printed names (spec section 6) [R1]. A rename changes one row; every cached item
 * name that uses it is rebuilt in the same transaction, with one audit entry. No sale line changes: a
 * sale keeps the name it printed.
 */
final readonly class Renames
{
    public function __construct(private Items $items, private AuditLog $audit) {}

    public function picklistValue(PicklistValue $value, string $name, ?string $actorId = null): int
    {
        return $this->rename($value, 'name', $name, 'picklist_value', $actorId,
            fn () => Item::query()->whereIn('id', DB::table('item_facets')->where('picklist_value_id', $value->id)->select('item_id')));
    }

    public function brand(Brand $brand, string $name, ?string $actorId = null): int
    {
        return $this->rename($brand, 'name', $name, 'brand', $actorId, fn () => Item::query()->where('brand_id', $brand->id));
    }

    public function categoryWord(Category $category, string $word, ?string $actorId = null): int
    {
        return $this->rename($category, 'word', $word, 'category', $actorId, fn () => Item::query()->where('category_id', $category->id));
    }

    /**
     * @param  callable(): Builder<Item>  $affected
     */
    private function rename(PicklistValue|Brand|Category $model, string $column, string $value, string $type, ?string $actorId, callable $affected): int
    {
        $value = trim($value);

        return DB::transaction(function () use ($model, $column, $value, $type, $actorId, $affected): int {
            $old = $model->getAttribute($column);
            $model->update([$column => $value]);

            $rebuilt = 0;
            foreach ($affected()->get() as $item) {
                $rebuilt += $this->items->rebuildName($item) ? 1 : 0;
            }

            $this->audit->record(new AuditEntry(action: "catalogue.{$type}_renamed", actorId: $actorId, subjectType: $type, subjectId: $model->id,
                before: [$column => $old], after: [$column => $value, 'item_names_rebuilt' => $rebuilt]));

            return $rebuilt;
        });
    }
}
