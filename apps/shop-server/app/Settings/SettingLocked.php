<?php

declare(strict_types=1);

namespace App\Settings;

use RuntimeException;

final class SettingLocked extends RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct("{$key} is locked: only HAQ's support team can change it, from the console or the shop profile.");
    }
}
