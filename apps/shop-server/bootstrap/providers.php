<?php

declare(strict_types=1);

use App\Audit\AuditServiceProvider;
use App\BackOffice\BackOfficeServiceProvider;
use App\Backups\BackupsServiceProvider;
use App\Catalogue\CatalogueServiceProvider;
use App\Counter\CounterServiceProvider;
use App\Identity\IdentityServiceProvider;
use App\Organisation\OrganisationServiceProvider;
use App\Platform\PlatformServiceProvider;
use App\Printing\PrintingServiceProvider;
use App\Providers\AppServiceProvider;
use App\Sales\SalesServiceProvider;
use App\Settings\SettingsServiceProvider;
use App\Shifts\ShiftsServiceProvider;

// One service provider per module (D23), in dependency order.
return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    OrganisationServiceProvider::class,
    AuditServiceProvider::class,
    SettingsServiceProvider::class,
    IdentityServiceProvider::class,
    CatalogueServiceProvider::class,
    ShiftsServiceProvider::class,
    SalesServiceProvider::class,
    PrintingServiceProvider::class,
    CounterServiceProvider::class,
    BackupsServiceProvider::class,
    BackOfficeServiceProvider::class,
];
