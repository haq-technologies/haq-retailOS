<?php

declare(strict_types=1);

namespace App\Catalogue;

use RuntimeException;

/**
 * The starter catalogue was loaded before the shop profile. Its load is recorded in the audit log of this
 * server's branch, and the profile makes the branch, so the profile comes first, as install.sh does it.
 */
final class NoShopProfile extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Apply the shop profile first: php artisan shop:apply-profile <file>. The starter catalogue is recorded '
            ."in this server's branch, and the profile makes the branch. Nothing was loaded."
        );
    }
}
