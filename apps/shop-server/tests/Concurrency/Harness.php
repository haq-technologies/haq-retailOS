<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabase;

/**
 * Runs scenarios in separate PHP processes that start at the same moment (spec section 14) [F6].
 * Every worker connects as the app role and commits for real; the test then checks what is in the
 * database. Nothing here uses RefreshDatabase or DatabaseTransactions.
 */
final class Harness
{
    /** The advisory lock key the workers wait on, so they all start together. */
    public const BARRIER = 7_240_601;

    /** Rebuilds the schema as the owner role and applies the mutability matrix. Commits. */
    public static function freshDatabase(): void
    {
        TestDatabase::assertSafe();
        Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner', '--force' => true]);
        Artisan::call('db:mutability', ['--database' => 'pgsql_owner']);
    }

    /**
     * Starts one process per scenario, releases them together and waits for all of them.
     *
     * @param  list<array{0: class-string<Scenario>, 1?: array<string, mixed>}>  $workers
     * @return list<array<string, mixed>> Each worker's result, in the order given.
     */
    public static function run(array $workers, int $timeoutSeconds = 60): array
    {
        $barrier = DB::connection();
        $barrier->select('SELECT pg_advisory_lock(?)', [self::BARRIER]);

        $processes = [];
        try {
            foreach ($workers as $i => $worker) {
                $process = new Process(
                    [PHP_BINARY, base_path('tests/Concurrency/worker.php'), $worker[0], (string) $i, json_encode($worker[1] ?? [], JSON_THROW_ON_ERROR)],
                    base_path(),
                    self::environment(),
                    timeout: $timeoutSeconds,
                );
                $process->start();
                $processes[] = $process;
            }

            self::waitForBarrier($barrier, count($processes), $processes);
        } finally {
            $barrier->select('SELECT pg_advisory_unlock(?)', [self::BARRIER]);
        }

        $results = [];
        foreach ($processes as $i => $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException("Worker {$i} failed ({$process->getExitCode()}):\n".$process->getErrorOutput().$process->getOutput());
            }
            $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
            /** @var array<string, mixed> $result */
            $result = json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);
            $results[] = $result;
        }

        return $results;
    }

    /**
     * @param  list<Process>  $processes
     */
    private static function waitForBarrier(Connection $db, int $count, array $processes): void
    {
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline) {
            /** @var object{waiting: int} $row */
            $row = $db->selectOne(
                "SELECT count(*) AS waiting FROM pg_locks WHERE locktype = 'advisory' AND objid = ? AND NOT granted",
                [self::BARRIER]
            );
            if ((int) $row->waiting >= $count) {
                return;
            }
            foreach ($processes as $i => $process) {
                if (! $process->isRunning() && ! $process->isSuccessful()) {
                    throw new RuntimeException("Worker {$i} stopped before the barrier:\n".$process->getErrorOutput());
                }
            }
            usleep(20_000);
        }
        throw new RuntimeException("Only some of the {$count} workers reached the barrier within 30 seconds.");
    }

    /**
     * The workers' environment: the same database settings as the test, so they use its database.
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $db = config()->array('database.connections.pgsql');
        $owner = config()->array('database.connections.pgsql_owner');

        return [
            'APP_ENV' => 'testing',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'],
            'DB_PASSWORD' => (string) $db['password'],
            'DB_OWNER_USERNAME' => (string) $owner['username'],
            'DB_OWNER_PASSWORD' => (string) $owner['password'],
        ];
    }
}
