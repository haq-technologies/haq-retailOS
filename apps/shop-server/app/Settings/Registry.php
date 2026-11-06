<?php

declare(strict_types=1);

namespace App\Settings;

use InvalidArgumentException;

/**
 * Every setting the shop server knows. Modules add theirs from their service providers.
 */
final class Registry
{
    /** @var array<string, Definition> */
    private array $definitions = [];

    public function define(Definition $definition): void
    {
        if (isset($this->definitions[$definition->key])) {
            throw new InvalidArgumentException("The setting {$definition->key} is defined twice.");
        }
        $this->definitions[$definition->key] = $definition;
    }

    public function get(string $key): Definition
    {
        return $this->definitions[$key] ?? throw new UnknownSetting($key);
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    /**
     * @return array<string, Definition>
     */
    public function all(): array
    {
        ksort($this->definitions);

        return $this->definitions;
    }
}
