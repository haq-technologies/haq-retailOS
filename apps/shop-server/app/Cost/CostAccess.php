<?php

declare(strict_types=1);

namespace App\Cost;

use App\Identity\Models\User;
use App\Identity\Role;
use App\Settings\Settings;

/**
 * Who sees and who enters cost (spec section 3) [F10, FU6]. The owner does both. A manager does neither,
 * unless the sysadmin turns on "manager sees cost" or "manager may enter cost" (which includes seeing it).
 * Every place that could show a cost asks here first; cost never leaves the server otherwise.
 */
final readonly class CostAccess
{
    public function __construct(private Settings $settings) {}

    public function canSee(User $user): bool
    {
        return $user->role === Role::Owner
            || ($user->role === Role::Manager && ($this->settings->get('cost.manager_sees_cost') === true || $this->settings->get('cost.manager_enters_cost') === true));
    }

    public function canEnter(User $user): bool
    {
        return $user->role === Role::Owner || ($user->role === Role::Manager && $this->settings->get('cost.manager_enters_cost') === true);
    }
}
