<?php

declare(strict_types=1);

namespace App\BackOffice\Http;

use App\BackOffice\Access;
use App\Catalogue\ReviewQueue;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Organisation\Models\Terminal;
use App\Platform\Http\Page;
use App\Reports\Dashboard;
use App\Reports\FirstSales;
use App\Reports\Flags;
use App\Reports\NeedsCost;
use App\Reports\Period;
use App\Sales\LostWindow;
use App\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use LogicException;

/**
 * The back office (spec section 12; wireframes BackDashboard and BackDashboardPhone): one Inertia page
 * that signs in with the name tiles and then talks to the JSON API with the acting-user token.
 */
final class BackOfficeController
{
    use ActsForUser;

    public function page(Request $request, Settings $settings): Response
    {
        $terminal = $request->attributes->get('terminal');
        if (! $terminal instanceof Terminal) {
            throw new LogicException('The EnrolledTerminal middleware must run first.');
        }

        return Page::render('back-office/index', [
            'terminal' => ['code' => $terminal->code, 'name' => $terminal->name, 'canSell' => $terminal->canSell()],
            'tiles' => User::query()->active()->whereNotNull('pin_hash')->whereIn('role', [Role::Owner->value, Role::Manager->value, Role::Storekeeper->value])
                ->orderBy('name')->get()
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->value, 'pinLength' => $u->role->pinLength()])->all(),
            'idleLockMinutes' => $settings->get('identity.idle_lock_minutes'),
        ]);
    }

    /** Who is signed in and what they may open. */
    public function me(Request $request, Access $access): JsonResponse
    {
        $actor = $this->actor($request);

        return response()->json(['user' => ['id' => $actor->id, 'name' => $actor->name, 'role' => $actor->role->value], 'can' => $access->for($actor)]);
    }

    /** The counts on the menu, only for the sections this person may open. */
    public function badges(Request $request, Access $access, ReviewQueue $review, Flags $flags, Dashboard $dashboard): JsonResponse
    {
        $can = $access->for($this->actor($request));
        $day = $dashboard->today();
        $firstSales = $can['prices'] ? count(array_filter(FirstSales::forDay($day, Period::day($day, $flags->zone())), fn (array $g) => $g['set'] === null)) : 0;
        $attention = $can['flags'] ? count(array_filter($flags->list(Period::named('last7', $flags->zone()), false, Flags::ATTENTION), fn (array $f) => $f['seen_at'] === null)) : 0;

        return response()->json([
            'costs' => $can['costs'] ? NeedsCost::queue(limit: 0)['total_items'] : 0,
            'flags' => $attention,
            'review' => $can['review'] ? $review->total() : 0,
            'prices' => $firstSales,
            'recovery' => $can['recovery'] ? LostWindow::waiting() : 0,
            // The lost-window screen joins the menu after a restore, and while a lost sale waits.
            'recovery_shown' => $can['recovery'] && LostWindow::shown(),
        ]);
    }

    public function dashboard(Request $request, Dashboard $dashboard, Access $access): JsonResponse
    {
        $actor = $this->allowed($request, 'dashboard');
        $day = $request->query('day');
        $data = $dashboard->forDay(is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? $day : $dashboard->today(), $access->allows($actor, 'profit'));

        // Profit amounts reveal cost: only for those who may see it (spec section 3) [F10].
        if (! $access->allows($actor, 'profit')) {
            /** @var array{confirmed: array<string, int>, estimated: array<string, int>} $profit */
            $profit = $data['profit'];
            unset($profit['confirmed']['profit_kobo'], $profit['estimated']['profit_kobo']);
            $data['profit'] = $profit;
        }

        return response()->json($data);
    }
}
