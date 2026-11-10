<?php

declare(strict_types=1);

namespace App\BackOffice;

use App\Cost\CostAccess;
use App\Identity\Models\User;
use App\Identity\Role;

/**
 * What each role may open in the back office (spec section 3, Roles and permissions). The owner sees all,
 * with profit; a manager sees the branch without profit or cost unless HAQ allows it; a storekeeper the
 * catalogue work; a cashier nothing here.
 */
final readonly class Access
{
    public function __construct(private CostAccess $cost) {}

    /**
     * @return array{dashboard: bool, profit: bool, costs: bool, prices: bool, review: bool, names: bool, categories: bool, promote: bool, flags: bool, summary: bool, catalogue: bool, settings: bool, health: bool, recovery: bool, audit: bool, pilot: bool, pilot_decide: bool, export: bool}
     */
    public function for(User $user): array
    {
        $role = $user->role;
        $boss = $role === Role::Owner || $role === Role::Manager;

        return [
            'dashboard' => $boss,
            'profit' => $this->cost->canSee($user),
            'costs' => $this->cost->canEnter($user),
            'prices' => $boss,
            // Merge and rename items, review barcode links and aliases: managers, the storekeeper and the owner.
            'review' => $boss || $role === Role::Storekeeper,
            // Add a brand (or a phone model) to the pick-list: managers and the owner.
            'names' => $boss,
            // Create or rename a category: the owner only.
            'categories' => $role === Role::Owner,
            // Make a Request-only typed line a proper item: the owner or the storekeeper (spec section 6) [F20].
            'promote' => $role === Role::Owner || $role === Role::Storekeeper,
            'flags' => $boss,
            'summary' => $boss,
            'catalogue' => $boss || $role === Role::Storekeeper,
            'settings' => $role === Role::Owner,
            // The server's health page: the sysadmin's page (spec section 4), shown to the owner until the
            // console exists (D45). Server health only, never sales or cost.
            'health' => $role === Role::Owner,
            // The lost-window screen after a restore: the manager re-enters lost sales (spec section 9) [F1].
            'recovery' => $boss,
            // Reading the audit log: a manager the branch's, redacted; the owner all, with sensitive values
            // (spec section 3) [D47]. A cashier reads their own on the counter; a storekeeper none.
            'audit' => $boss,
            // The measured pilot (D56): managers and the owner enter the notebook's totals and trace each
            // difference; only the owner starts the 14 days and records the decision at gate G5.
            'pilot' => $boss,
            'pilot_decide' => $role === Role::Owner,
            // The shop's whole record, cost included, for the owner to keep (spec section 14; D57).
            'export' => $role === Role::Owner,
        ];
    }

    public function allows(User $user, string $ability): bool
    {
        return $this->for($user)[$ability] ?? false;
    }
}
