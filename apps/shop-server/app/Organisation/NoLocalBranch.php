<?php

declare(strict_types=1);

namespace App\Organisation;

use RuntimeException;

final class NoLocalBranch extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This server has no branch yet. Apply the shop profile first (php artisan shop:apply-profile).');
    }
}
