<?php

declare(strict_types=1);

namespace App\Settings;

use InvalidArgumentException;

final class InvalidSettingValue extends InvalidArgumentException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(string $key, array $errors)
    {
        parent::__construct("{$key} cannot take that value: ".implode(' ', $errors));
    }
}
