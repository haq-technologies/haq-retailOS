<?php

declare(strict_types=1);

namespace App\Settings\Profile;

use App\Settings\Registry;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * A shop profile: the file the installer applies so every install is the same (spec sections 6 and 11).
 * In the pilot the sysadmin makes it with `php artisan shop:make-profile` before the visit [F25, D14].
 * It holds shop data, so it is never committed.
 */
final readonly class Profile
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  list<array{code: string, name: string, drawer: bool, kind: string, printer_host: ?string, printer_port: ?int}>  $terminals
     * @param  array<string, array{value: mixed, locked: ?bool}>  $settings
     */
    public function __construct(
        public string $shopName,
        public string $branchCode,
        public string $branchName,
        public array $terminals,
        public array $settings,
    ) {}

    public static function fromFile(string $path, Registry $registry): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("There is no profile at {$path}.");
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            throw new InvalidArgumentException("{$path} is not a JSON profile.");
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data, $registry);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, Registry $registry): self
    {
        $validator = Validator::make($data, [
            'schema_version' => ['required', 'integer', 'in:'.self::SCHEMA_VERSION],
            'shop.name' => ['required', 'string', 'max:120'],
            'branch.code' => ['required', 'string', 'regex:/^[A-Z0-9]{2,8}$/'],
            'branch.name' => ['required', 'string', 'max:120'],
            'terminals' => ['required', 'array', 'min:1', 'max:20'],
            'terminals.*.code' => ['required', 'string', 'regex:/^[TD][0-9]{2}$/', 'distinct'],
            'terminals.*.name' => ['required', 'string', 'max:60'],
            'terminals.*.drawer' => ['required', 'boolean'],
            'terminals.*.kind' => ['sometimes', 'in:counter,back_office'],
            'terminals.*.printer' => ['sometimes', 'nullable', 'array'],
            'terminals.*.printer.host' => ['required_with:terminals.*.printer', 'string', 'max:64', 'regex:/^[A-Za-z0-9.-]+$/'],
            'terminals.*.printer.port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'receipt' => ['required', 'array'],
            'receipt.shop_name' => ['required'],
            'settings' => ['sometimes', 'array'],
        ], [
            'branch.code.regex' => 'The branch code must be 2 to 8 capital letters or digits, such as B01.',
            'terminals.*.code.regex' => 'Each counter\'s code must be T and two digits, such as T01 (it starts every receipt number); a back-office device\'s is D and two digits, such as D01.',
        ]);
        if ($validator->fails()) {
            throw new InvalidProfile(array_values($validator->errors()->all()));
        }

        // A counter is T01 and may have a drawer; a back-office device is D01, has no drawer and never sells (D29).
        $deviceErrors = [];
        /** @var list<array{code: string, drawer: bool, kind?: string}> $listed */
        $listed = $data['terminals'];
        foreach ($listed as $terminal) {
            $kind = $terminal['kind'] ?? 'counter';
            if ($kind === 'counter' && ! str_starts_with($terminal['code'], 'T')) {
                $deviceErrors[] = "{$terminal['code']} is a counter, so its code starts with T, such as T01.";
            }
            if ($kind === 'back_office' && ! str_starts_with($terminal['code'], 'D')) {
                $deviceErrors[] = "{$terminal['code']} is a back-office device, so its code starts with D, such as D01.";
            }
            if ($kind === 'back_office' && (bool) $terminal['drawer']) {
                $deviceErrors[] = "{$terminal['code']} is a back-office device, so it has no cash drawer.";
            }
        }
        if ($deviceErrors !== []) {
            throw new InvalidProfile($deviceErrors);
        }

        $settings = [];
        /** @var array<string, mixed> $receipt */
        $receipt = $data['receipt'];
        foreach ($receipt as $field => $value) {
            $settings["receipt.{$field}"] = ['value' => $value, 'locked' => null];
        }
        /** @var array<string, mixed> $extra */
        $extra = $data['settings'] ?? [];
        foreach ($extra as $key => $entry) {
            $settings[$key] = is_array($entry) && array_key_exists('value', $entry)
                ? ['value' => $entry['value'], 'locked' => isset($entry['locked']) ? (bool) $entry['locked'] : null]
                : ['value' => $entry, 'locked' => null];
        }

        $unknown = array_values(array_filter(array_keys($settings), fn (string $key) => ! $registry->has($key)));
        if ($unknown !== []) {
            throw new InvalidProfile(array_map(fn (string $key) => "The profile sets {$key}, which is not a setting this version knows.", $unknown));
        }

        /** @var array{shop: array{name: string}, branch: array{code: string, name: string}, terminals: list<array{code: string, name: string, drawer: bool, kind?: string, printer?: array{host: string, port?: int}|null}>} $data */
        return new self(
            shopName: $data['shop']['name'],
            branchCode: $data['branch']['code'],
            branchName: $data['branch']['name'],
            terminals: array_map(fn (array $t) => [
                'code' => $t['code'], 'name' => $t['name'], 'drawer' => (bool) $t['drawer'], 'kind' => $t['kind'] ?? 'counter',
                'printer_host' => $t['printer']['host'] ?? null, 'printer_port' => isset($t['printer']) ? (int) ($t['printer']['port'] ?? 9100) : null,
            ], $data['terminals']),
            settings: $settings,
        );
    }

    /**
     * The JSON document a new profile starts as.
     *
     * @param  list<array{code: string, name: string, drawer: bool, kind?: string}>  $terminals
     * @param  array<string, mixed>  $receipt
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function document(string $shop, string $branchCode, string $branchName, array $terminals, array $receipt, array $settings = []): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'shop' => ['name' => $shop],
            'branch' => ['code' => $branchCode, 'name' => $branchName],
            'terminals' => $terminals,
            'receipt' => $receipt,
            'settings' => (object) $settings,
        ];
    }
}
