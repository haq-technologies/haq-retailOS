<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Identity\Users;
use App\Organisation\Models\Terminal;

/**
 * People and enrolled counters for identity tests.
 */
final class People
{
    public static function add(string $name, Role $role, ?string $pin = null): User
    {
        return app(Users::class)->add($name, $role, $pin ?? ($role->canApprove() ? '246810' : '1357'));
    }

    /** Enrols a terminal and returns the device cookie's value. */
    public static function enrol(Terminal $terminal): string
    {
        $enrolment = app(Enrolment::class);

        return $enrolment->redeem($enrolment->issueCode($terminal)) ?? throw new \RuntimeException('Enrolment failed.');
    }
}
