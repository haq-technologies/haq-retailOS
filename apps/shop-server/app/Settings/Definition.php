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
     * @param  ?string  $help  For a setting the owner changes: one plain sentence on what it does.
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
        // Shown to the owner in the back office's Shop settings, where they change it unless HAQ has locked it (D37).
        public bool $ownerFacing = false,
        public ?string $help = null,
        // How Shop settings asks for it: time (HH:MM), money (whole naira, stored as kobo) or text.
        public string $ownerKind = 'text',
    ) {}
}
