<?php

declare(strict_types=1);

namespace App\Catalogue;

use RuntimeException;

/**
 * A new item the counter may not create, with a message the cashier can act on. With $similar set, the
 * counter shows the near matches and may ask again with "create anyway".
 *
 * @phpstan-type Similar list<array{id: string, name: string}>
 */
final class NewItemRefused extends RuntimeException
{
    /**
     * @param  list<array{id: string, name: string}>  $similar
     */
    public function __construct(string $message, public readonly array $similar = [])
    {
        parent::__construct($message);
    }
}
