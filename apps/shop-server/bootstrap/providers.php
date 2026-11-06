<?php

declare(strict_types=1);

use App\Platform\PlatformServiceProvider;
use App\Providers\AppServiceProvider;

// One service provider per module (D23), in dependency order.
return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
];
