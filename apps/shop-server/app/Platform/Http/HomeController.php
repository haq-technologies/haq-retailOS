<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Illuminate\Support\Facades\DB;
use Inertia\Response;

/**
 * The placeholder home page while Phase 2 builds the foundation. It proves the stack end to end:
 * nginx, PHP-FPM, PostgreSQL, Inertia and the React build.
 */
class HomeController
{
    public function __invoke(): Response
    {
        /** @var object{server_version: string} $row */
        $row = DB::selectOne('SHOW server_version');

        return Page::render('home', [
            'version' => config()->string('app.version'),
            'database' => 'PostgreSQL '.strtok($row->server_version, ' '),
        ]);
    }
}
