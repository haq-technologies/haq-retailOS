<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/**
 * The only way a page is rendered. Props must be explicit arrays, scalars or API Resources: never an
 * Eloquent model, because model hiding is not role-aware and cost must never reach a role that may
 * not see it (spec sections 2 and 4) [F10]. An architecture test stops any other use of Inertia.
 */
final class Page
{
    /**
     * @param  array<string, mixed>  $props
     */
    public static function render(string $component, array $props = []): Response
    {
        self::guard($props, $component);

        return Inertia::render($component, $props);
    }

    private static function guard(mixed $value, string $path): void
    {
        if ($value instanceof Model || $value instanceof EloquentCollection || $value instanceof Paginator) {
            throw new LogicException(
                "{$path} passes ".$value::class.' to a page. Pass an API Resource or an explicit array instead.'
            );
        }
        if (is_iterable($value)) {
            foreach ($value as $key => $item) {
                self::guard($item, $path.'.'.(is_scalar($key) ? (string) $key : '?'));
            }
        }
    }
}
