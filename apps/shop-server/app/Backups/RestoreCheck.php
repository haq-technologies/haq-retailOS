<?php

declare(strict_types=1);

namespace App\Backups;

use App\Audit\Verifier;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The weekly test restore's checks (spec section 9) [F24]: the restored copy answers, its row counts are
 * sane against the live database (an append-only table can only have grown since the backup), and every
 * audit chain verifies on it.
 */
final readonly class RestoreCheck
{
    /** Append-only tables: the copy can never hold more rows than the live database. */
    private const GROWING = ['sales', 'sale_lines', 'payments', 'epochs', 'audit_log', 'audit_roots', 'cash_events', 'settings_history'];

    public function __construct(private Verifier $verifier) {}

    /**
     * @return array{ok: bool, counts: array<string, array{copy: int, live: int}>, problems: list<string>}
     */
    public function run(string $host, int $port): array
    {
        $live = config('database.connections.pgsql');
        config(['database.connections.restore_test' => [...(is_array($live) ? $live : []), 'host' => $host, 'port' => $port]]);
        DB::purge('restore_test');

        $problems = [];
        $counts = [];
        $default = DB::getDefaultConnection();
        try {
            foreach (self::GROWING as $table) {
                $copy = (int) DB::connection('restore_test')->table($table)->count();
                $now = (int) DB::connection($default)->table($table)->count();
                $counts[$table] = ['copy' => $copy, 'live' => $now];
                if ($copy > $now) {
                    $problems[] = "The copy has more {$table} rows ({$copy}) than the live database ({$now}).";
                }
            }
            // The verifier reads the default connection, so it runs on the copy here.
            DB::setDefaultConnection('restore_test');
            $problems = [...$problems, ...$this->verifier->problems()];
        } catch (Throwable $e) {
            $problems[] = 'The restored copy could not be checked: '.$e->getMessage();
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('restore_test');
        }

        return ['ok' => $problems === [], 'counts' => $counts, 'problems' => $problems];
    }
}
