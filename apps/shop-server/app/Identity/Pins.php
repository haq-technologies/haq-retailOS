<?php

declare(strict_types=1);

namespace App\Identity;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use LogicException;

/**
 * PINs, stored with Argon2id (spec section 3) [F8]. PINs need not be unique: the name tile identifies
 * the person first, so a collision never leaks.
 */
final class Pins
{
    public function hash(string $pin): string
    {
        return $this->hasher()->make($pin);
    }

    public function check(string $pin, ?string $hash): bool
    {
        if ($hash === null) {
            // Spend the same time as a real check, so a missing PIN cannot be told apart by timing.
            $this->hasher()->check($pin, $this->dummy());

            return false;
        }

        return $this->hasher()->check($pin, $hash);
    }

    public function assertValid(string $pin, Role $role): void
    {
        [$min, $max] = $role->pinLength();
        if (preg_match('/^[0-9]+$/', $pin) !== 1 || strlen($pin) < $min || strlen($pin) > $max) {
            throw new InvalidArgumentException($min === $max
                ? "A {$role->value}'s PIN is {$min} digits."
                : "A {$role->value}'s PIN is {$min} to {$max} digits.");
        }
    }

    private function hasher(): Hasher
    {
        $hasher = Hash::driver('argon2id');

        return $hasher instanceof Hasher ? $hasher : throw new LogicException('The Argon2id hasher is missing.');
    }

    private function dummy(): string
    {
        static $hash = null;

        return $hash ??= $this->hasher()->make('000000');
    }
}
