<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Organisation\Models\Branch;
use App\Organisation\Models\Tenant;
use App\Organisation\Models\Terminal;

/**
 * A small shop for tests: one tenant, its local branch and two terminals.
 */
final class Shop
{
    /**
     * @return array{tenant: Tenant, branch: Branch, terminals: list<Terminal>}
     */
    public static function create(int $terminals = 2): array
    {
        $tenant = Tenant::query()->create(['name' => 'Test Accessories']);
        $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'code' => 'B01', 'name' => 'Main branch', 'is_local' => true]);
        $list = [];
        for ($i = 1; $i <= $terminals; $i++) {
            $list[] = Terminal::query()->create(['branch_id' => $branch->id, 'code' => sprintf('T%02d', $i), 'name' => "Counter {$i}"]);
        }

        return ['tenant' => $tenant, 'branch' => $branch, 'terminals' => $list];
    }
}
