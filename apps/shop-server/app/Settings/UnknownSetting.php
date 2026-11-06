<?php

declare(strict_types=1);

namespace App\Settings;

use InvalidArgumentException;

final class UnknownSetting extends InvalidArgumentException
{
    public function __construct(string $key)
    {
        parent::__construct("There is no setting called {$key}.");
    }
}
