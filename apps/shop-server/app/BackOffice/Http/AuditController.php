<?php

declare(strict_types=1);

namespace App\BackOffice\Http;

use App\Audit\History;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The audit log in the back office (spec sections 3 and 10; check D5) [F10, D47]: a manager reads the
 * branch's actions, redacted; the owner reads all of them with the sensitive values. Read-only: nobody can
 * change or delete an entry, the owner included.
 */
final class AuditController
{
    use ActsForUser;

    public function index(Request $request, Settings $settings): JsonResponse
    {
        $actor = $this->allowed($request, 'audit');
        $zone = $settings->get('business.timezone');
        $zone = is_string($zone) ? $zone : 'Africa/Lagos';
        $day = $request->query('day');
        $from = is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? CarbonImmutable::parse($day, $zone)->startOfDay() : null;
        $person = $request->query('person');
        $before = $request->query('before');

        $page = History::page(
            actorId: is_string($person) && preg_match('/^[0-9a-z]{26}$/', $person) === 1 ? $person : null,
            // Sensitive values (cost, credit limits) are the owner's alone (spec section 3).
            sensitive: $actor->role === Role::Owner,
            from: $from,
            to: $from?->addDay(),
            before: is_string($before) ? $before : null,
        );

        return response()->json([...$page,
            'sensitive' => $actor->role === Role::Owner,
            'people' => User::query()->orderBy('name')->get(['id', 'name', 'role'])->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->value])->all(),
        ]);
    }
}
