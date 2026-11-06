<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Catalogue\Models\CategoryTemplate;
use App\Catalogue\Models\PicklistValue;

/**
 * Generic shapes, built on demand from a category's generator rule (spec section 6) [R2]: the
 * combination of the listed facets' values (cables: connector pair by length), with a blank where the
 * rule allows it and minus the excluded values. They are offered at the counter, and an item row is
 * created only on the first sale, so impossible combinations never reach a report.
 */
final class Shapes
{
    /**
     * @return list<array<string, string|null>> Each shape: facet key => pick-list value ID, or null for a blank.
     */
    public static function for(CategoryTemplate $template): array
    {
        $rule = $template->generator;
        if ($rule === null) {
            return [];
        }
        $lists = array_column($template->facets, 'list', 'key');

        $shapes = [[]];
        foreach ($rule['combine'] as $key) {
            $excluded = $rule['exclude'][$key] ?? [];
            $options = PicklistValue::query()->where('list', $lists[$key] ?? '')->where('status', 'active')
                ->orderBy('position')->get(['id', 'seed_id'])
                ->reject(fn (PicklistValue $v) => in_array($v->seed_id, $excluded, true))
                ->map(fn (PicklistValue $v) => $v->id)->values()->all();
            if (in_array($key, $rule['blank_allowed'], true)) {
                $options[] = null;
            }

            $next = [];
            foreach ($shapes as $shape) {
                foreach ($options as $option) {
                    $next[] = [...$shape, $key => $option];
                }
            }
            $shapes = $next;
        }

        return $shapes;
    }
}
