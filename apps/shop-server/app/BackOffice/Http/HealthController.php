<?php

declare(strict_types=1);

namespace App\BackOffice\Http;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Health\Health;
use App\Health\Snapshot;
use App\Platform\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The server's health page (spec section 4; wireframe HealthPage) [F15, F24, D45]. Until the sysadmin
 * console exists, the owner opens it in the back office: server health only, never sales or cost. The
 * owner may ask for a backup now and confirm the server's time.
 */
final class HealthController
{
    use ActsForUser;

    public function index(Request $request, Health $health): JsonResponse
    {
        $this->allowed($request, 'health');

        return response()->json($health->view());
    }

    /** Back up now: the scheduler passes it to the backup service within a minute. */
    public function backup(Request $request, AuditLog $audit): JsonResponse
    {
        $actor = $this->allowed($request, 'health');
        if (! is_array(Snapshot::read()['backups'] ?? null)) {
            return response()->json(['message' => 'This server has no backup disk, so there is nothing to back up to. Call HAQ support.'], 422);
        }
        Cache::forever(Snapshot::BACKUP_REQUEST, CarbonImmutable::now()->toIso8601String());
        $audit->record(new AuditEntry(action: 'backup.requested', actorId: $actor->id, subjectType: 'server'));

        return response()->json(['message' => 'The backup starts within a minute or two and takes a few minutes. Selling goes on meanwhile.'], 202);
    }

    /** The owner confirms the server's time is right (spec section 4) [F15]. */
    public function confirmClock(Request $request, Clock $clock, AuditLog $audit): JsonResponse
    {
        $actor = $this->allowed($request, 'health');
        $before = $clock->state();
        try {
            $clock->confirm($actor->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $audit->record(new AuditEntry(action: 'clock.confirmed', actorId: $actor->id, subjectType: 'server',
            before: ['state' => $before['state'], 'reason' => $before['reason']], after: ['server_time' => CarbonImmutable::now()->toIso8601String()]));

        return response()->json(['clock' => $clock->state()]);
    }
}
