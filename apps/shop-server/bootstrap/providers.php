<?php

declare(strict_types=1);

use App\Audit\AuditServiceProvider;
use App\Identity\IdentityServiceProvider;
use App\Organisation\OrganisationServiceProvider;
use App\Platform\PlatformServiceProvider;
use App\Providers\AppServiceProvider;
use App\Settings\SettingsServiceProvider;

// One service provider per module (D23), in dependency order.
return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    OrganisationServiceProvider::class,
    AuditServiceProvider::class,
    SettingsServiceProvider::class,
    IdentityServiceProvider::class,
];
