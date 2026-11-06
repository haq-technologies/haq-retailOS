<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * One known setting: its key, what values it accepts and its default. Each module defines the settings
 * its features use, when those features are built, so no policy value is invented ahead of time.
 */
final readonly class Definition
{
    /**
     * @param  list<string>  $scopes  Where the setting may be set: shop, branch or both.
     * @param  array<int, mixed>  $rules  Laravel validation rules for the value.
     */
    public function __construct(
        public string $key,
        public string $label,
        public mixed $default,
        public array $rules,
        public array $scopes = ['shop'],
        public bool $lockedByDefault = false,
        public bool $sensitive = false,
        public ?string $source = null,
    ) {}
}
