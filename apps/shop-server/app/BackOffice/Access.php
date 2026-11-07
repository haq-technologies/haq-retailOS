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
     * @return array{dashboard: bool, profit: bool, costs: bool, prices: bool, review: bool, names: bool, categories: bool, promote: bool, flags: bool, summary: bool, catalogue: bool, settings: bool, health: bool}
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
            'health' => $role === Role::Owner,
        ];
    }

    public function allows(User $user, string $ability): bool
    {
        return $this->for($user)[$ability] ?? false;
    }
}
