<?php

declare(strict_types=1);

namespace App\Identity;

/**
 * The roles at a shop (spec section 3). The sysadmin is HAQ's support team and never signs in at a counter.
 */
enum Role: string
{
    case Cashier = 'cashier';
    case Manager = 'manager';
    case Storekeeper = 'storekeeper';
    case Owner = 'owner';

    /**
     * One PIN per person. A manager's or owner's PIN is 6 digits, because it also approves (spec section 3, D24).
     *
     * @return array{int, int} The shortest and longest PIN.
     */
    public function pinLength(): array
    {
        return $this->canApprove() ? [6, 6] : [4, 6];
    }

    public function canApprove(): bool
    {
        return $this === self::Manager || $this === self::Owner;
    }

    /** A manager or owner signed in at a counter is an elevated session, which expires sooner [F8]. */
    public function isElevated(): bool
    {
        return $this->canApprove();
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
