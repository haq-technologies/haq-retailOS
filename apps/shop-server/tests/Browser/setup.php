<?php

declare(strict_types=1);

/*
 * Builds a fresh shop for the browser tests (e2e/), in the retail_e2e database only: the example profile,
 * the starter catalogue, a cashier and a manager, and a one-time enrolment code for each counter, written
 * to e2e/.state.json. The names and PINs are placeholders, as in the feature tests.
 */

use App\Identity\Enrolment;
use App\Identity\Role;
use App\Identity\Users;
use App\Organisation\Models\Terminal;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.connections.pgsql.database') !== 'retail_e2e') {
    fwrite(STDERR, "The browser tests run only against the retail_e2e database.\n");
    exit(1);
}

$run = function (string $command, array $args = []): void {
    $code = Artisan::call($command, $args);
    if ($code !== 0) {
        fwrite(STDERR, Artisan::output());
        exit($code);
    }
};

$run('migrate:fresh', ['--database' => 'pgsql_owner', '--force' => true]);
$run('db:mutability', ['--database' => 'pgsql_owner']);
DB::purge('pgsql');
$run('shop:apply-profile', ['path' => base_path('docker/profile.example.json')]);
$run('catalogue:seed');

// The counters print to the stand-in printer beside the test server.
DB::table('terminals')->where('kind', 'counter')->update(['printer_host' => '127.0.0.1', 'printer_port' => 9100]);

app(Users::class)->add('Amina', Role::Cashier, '1357');
app(Users::class)->add('Kemi', Role::Manager, '246810');

$codes = [];
foreach (Terminal::query()->where('kind', 'counter')->orderBy('code')->get() as $terminal) {
    $codes[$terminal->code] = app(Enrolment::class)->issueCode($terminal);
}

file_put_contents(base_path('e2e/.state.json'), json_encode(['codes' => $codes], JSON_PRETTY_PRINT));
echo 'The browser-test shop is ready: '.implode(', ', array_keys($codes)).PHP_EOL;
