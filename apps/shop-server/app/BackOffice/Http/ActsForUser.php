<?php

declare(strict_types=1);

namespace App\BackOffice\Http;

use App\BackOffice\Access;
use App\Identity\Models\User;
use Illuminate\Http\Request;
use LogicException;

/** The signed-in person, refused unless their role may open this part of the back office. */
trait ActsForUser
{
    private function actor(Request $request): User
    {
        $actor = $request->attributes->get('acting_user');
        if (! $actor instanceof User) {
            throw new LogicException('The ActingUser middleware must run first.');
        }

        return $actor;
    }

    private function allowed(Request $request, string $ability): User
    {
        $actor = $this->actor($request);
        abort_unless(app(Access::class)->allows($actor, $ability), 403, 'This part of the back office is not for your role.');

        return $actor;
    }
}
