<?php

declare(strict_types=1);

namespace App\Identity\Console;

use App\Identity\Models\User;
use Illuminate\Console\Command;

/**
 * Finds the one person a support command acts on, by the name on their tile or by their ID (D74, QA finding
 * P15). Names need not be unique: when two active people share a name, nothing is done and their IDs are
 * shown, so support can give the one they mean.
 *
 * @phpstan-require-extends Command
 */
trait FindsPerson
{
    private function person(string $given): ?User
    {
        $given = trim($given);
        $byId = preg_match('/^[0-9A-Za-z]{26}$/', $given) === 1 ? User::query()->find(strtolower($given)) : null;
        if ($byId !== null) {
            return $byId;
        }

        $matches = User::query()->active()->whereRaw('lower(name) = lower(?)', [$given])->orderBy('id')->get();
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->isEmpty()) {
            $this->components->error("There is no active person called {$given}. `sudo haq users` lists everyone.");

            return null;
        }
        $this->components->error("{$matches->count()} active people are called {$given}. Nothing was changed. Give the ID of the one you mean:");
        $this->table(['Name', 'Role', 'ID'], $matches->map(fn (User $u) => [$u->name, $u->role->label(), $u->id])->all());

        return null;
    }
}
