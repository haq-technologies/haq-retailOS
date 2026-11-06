<?php

declare(strict_types=1);

use App\Audit\AuditServiceProvider;
use App\Organisation\OrganisationServiceProvider;
use App\Platform\PlatformServiceProvider;
use App\Providers\AppServiceProvider;

// One service provider per module (D23), in dependency order.
return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    OrganisationServiceProvider::class,
    AuditServiceProvider::class,
];
