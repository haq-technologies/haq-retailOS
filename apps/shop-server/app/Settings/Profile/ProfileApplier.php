<?php

declare(strict_types=1);

namespace App\Settings\Profile;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Organisation\Models\Branch;
use App\Organisation\Models\Tenant;
use App\Organisation\Models\Terminal;
use App\Platform\Ulid;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Applies a shop profile: the shop, this server's branch, its terminals and their drawers, and the
 * settings (receipt text locked to the sysadmin, D14). Applying the same profile twice changes nothing.
 * Nothing is ever deleted: a terminal missing from the profile is left as it is and reported.
 */
final readonly class ProfileApplier
{
    public function __construct(
        private Settings $settings,
        private AuditLog $audit,
    ) {}

    /**
     * @return list<string> What changed, one line each; empty when the server already matched.
     */
    public function apply(Profile $profile): array
    {
        return DB::transaction(function () use ($profile): array {
            $changes = [];

            $tenant = Tenant::query()->first();
            if ($tenant === null) {
                $tenant = Tenant::query()->create(['name' => $profile->shopName]);
                $changes[] = "Created the shop {$profile->shopName}.";
            } elseif ($tenant->name !== $profile->shopName) {
                $changes[] = "Renamed the shop from {$tenant->name} to {$profile->shopName}.";
                $tenant->update(['name' => $profile->shopName]);
            }

            $branch = Branch::query()->where('is_local', true)->first();
            if ($branch !== null && $branch->code !== $profile->branchCode) {
                throw new RuntimeException("This server runs branch {$branch->code}; a profile for branch {$profile->branchCode} cannot be applied to it.");
            }
            if ($branch === null) {
                $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'code' => $profile->branchCode, 'name' => $profile->branchName, 'is_local' => true]);
                $changes[] = "Created the branch {$profile->branchCode} ({$profile->branchName}) for this server.";
            } elseif ($branch->name !== $profile->branchName) {
                $changes[] = "Renamed the branch {$branch->code} to {$profile->branchName}.";
                $branch->update(['name' => $profile->branchName]);
            }

            foreach ($profile->terminals as $wanted) {
                $terminal = Terminal::query()->where('branch_id', $branch->id)->where('code', $wanted['code'])->first();
                if ($terminal === null) {
                    $terminal = Terminal::query()->create(['branch_id' => $branch->id, 'code' => $wanted['code'], 'name' => $wanted['name']]);
                    $changes[] = "Added the terminal {$wanted['code']} ({$wanted['name']}).";
                } elseif ($terminal->name !== $wanted['name']) {
                    $changes[] = "Renamed the terminal {$wanted['code']} to {$wanted['name']}.";
                    $terminal->update(['name' => $wanted['name']]);
                }

                $drawer = DB::table('drawers')->where('terminal_id', $terminal->id)->first();
                if ($drawer === null) {
                    DB::table('drawers')->insert([
                        'id' => Ulid::generate(), 'branch_id' => $branch->id, 'terminal_id' => $terminal->id,
                        'label' => "{$wanted['code']} drawer", 'fitted' => $wanted['drawer'], 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $changes[] = $wanted['drawer'] ? "{$wanted['code']} has a cash drawer." : "{$wanted['code']} has no cash drawer.";
                } elseif ((bool) $drawer->fitted !== $wanted['drawer']) {
                    DB::table('drawers')->where('id', $drawer->id)->update(['fitted' => $wanted['drawer'], 'updated_at' => now()]);
                    $changes[] = $wanted['drawer'] ? "{$wanted['code']} now has a cash drawer." : "{$wanted['code']} no longer has a cash drawer.";
                }
            }

            $listed = array_column($profile->terminals, 'code');
            foreach (Terminal::query()->where('branch_id', $branch->id)->whereNotIn('code', $listed)->get() as $unlisted) {
                $changes[] = "The terminal {$unlisted->code} is not in the profile; it was left as it is.";
            }

            foreach ($profile->settings as $key => $setting) {
                if ($this->settings->set($key, $setting['value'], 'profile', locked: $setting['locked'])) {
                    $changes[] = "Set {$key}.";
                }
            }

            $structural = array_values(array_filter($changes, fn (string $c) => ! str_starts_with($c, 'Set ') && ! str_contains($c, 'left as it is')));
            if ($structural !== []) {
                $this->audit->record(new AuditEntry(
                    action: 'shop.profile_applied',
                    device: 'profile',
                    subjectType: 'branch',
                    subjectId: $branch->id,
                    after: ['changes' => $structural],
                ));
            }

            return $changes;
        });
    }
}
