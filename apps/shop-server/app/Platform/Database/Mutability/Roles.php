<?php

declare(strict_types=1);

namespace App\Platform\Database\Mutability;

/**
 * The three database roles (spec section 4) [F3]. The owner role owns the tables and is used only by
 * the one-shot migration job; the app role runs the shop; the reporting role reads cost-safe views.
 */
final readonly class Roles
{
    public function __construct(
        public string $app,
        public string $reporting,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config()->string('database.roles.app'),
            config()->string('database.roles.reporting'),
        );
    }
}
