<?php

declare(strict_types=1);

namespace App\BackOffice\Http;

use App\Identity\Models\User;
use App\Identity\Role;
use App\Settings\Definition;
use App\Settings\InvalidSettingValue;
use App\Settings\Registry;
use App\Settings\SettingLocked;
use App\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * The owner's Shop settings in the back office (spec section 12; D37): the settings marked for the owner,
 * which the owner changes unless HAQ has locked them (spec section 11, lock control). Every change goes
 * into the settings history and the audit trail.
 */
final class SettingsController
{
    public function index(Request $request, Registry $registry, Settings $settings): JsonResponse
    {
        $this->owner($request);

        // In the order the owner reads them: by name.
        $owned = array_filter($registry->all(), fn (Definition $d) => $d->ownerFacing);
        usort($owned, fn (Definition $a, Definition $b) => strcmp($a->label, $b->label));

        return response()->json(['settings' => array_map(fn (Definition $d) => [
            'key' => $d->key,
            'label' => $d->label,
            'help' => $d->help,
            'kind' => $d->ownerKind,
            'value' => $settings->get($d->key),
            'locked' => $settings->isLocked($d->key),
        ], $owned)]);
    }

    public function update(Request $request, Registry $registry, Settings $settings): JsonResponse
    {
        $owner = $this->owner($request);
        $data = $request->validate(['key' => ['required', 'string', 'max:80'], 'value' => ['present']]);
        $key = (string) $data['key'];
        if (! $registry->has($key) || ! $registry->get($key)->ownerFacing) {
            return response()->json(['message' => 'That is not one of the shop settings.'], 404);
        }
        $value = is_string($data['value']) && trim($data['value']) === '' ? null : $data['value'];

        try {
            $settings->set($key, $value, 'owner', actorId: $owner->id);
        } catch (SettingLocked) {
            return response()->json(['message' => 'HAQ support has locked this setting. Ask them to change it.'], 423);
        } catch (InvalidSettingValue) {
            $hint = match ($registry->get($key)->ownerKind) {
                'time' => ' A time is written like 21:30.',
                'money' => ' Type an amount in naira, such as 5000.',
                'number' => ' Type a whole number, such as 10.',
                default => '',
            };

            return response()->json(['message' => 'That value is not right for this setting.'.$hint], 422);
        }

        return response()->json(['key' => $key, 'value' => $settings->get($key)]);
    }

    private function owner(Request $request): User
    {
        $actor = $request->attributes->get('acting_user');
        if (! $actor instanceof User) {
            throw new LogicException('The ActingUser middleware must run first.');
        }
        abort_unless($actor->role === Role::Owner, 403, 'Only the owner changes the shop settings.');

        return $actor;
    }
}
