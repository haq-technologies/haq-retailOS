<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Illuminate\Support\Facades\DB;
use Inertia\Response;

/**
 * The server's own page, which anyone on the shop's network can open: it proves the stack end to end
 * (nginx, PHP-FPM, PostgreSQL, Inertia and the React build) and names the release. It never names the
 * database's version, which would only help someone looking for a weakness (AU10, D99).
 */
class HomeController
{
    public function __invoke(): Response
    {
        DB::selectOne('SELECT 1');

        return Page::render('home', [
            'version' => config()->string('app.version'),
            'database' => 'Answering',
        ]);
    }
}
