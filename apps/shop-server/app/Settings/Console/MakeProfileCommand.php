<?php

declare(strict_types=1);

namespace App\Settings\Console;

use App\Settings\Profile\Profile;
use App\Settings\Registry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

/**
 * Makes a shop profile before the visit (spec section 6, phase 0) [F25, D14]. In the pilot the console
 * is not built yet, so the sysadmin uses this. The profile holds shop data: keep it out of Git.
 */
#[AsCommand(name: 'shop:make-profile')]
class MakeProfileCommand extends Command
{
    protected $signature = 'shop:make-profile
        {--shop= : The shop name}
        {--branch-code=B01 : The branch code}
        {--branch-name= : The branch name}
        {--terminals=1 : How many counters}
        {--drawers= : Which counters have a cash drawer, such as T01,T02 (default: none)}
        {--printers= : The receipt printer of each counter, such as T01=192.168.1.21,T02=192.168.1.22 (port 9100 unless given, as 192.168.1.21:9100)}
        {--back-office=0 : How many back-office devices, such as a phone or laptop for the owner (D01, D02; they cannot sell)}
        {--receipt-name= : The shop name as the receipt prints it (default: the shop name)}
        {--address= : The address on the receipt}
        {--phone= : The phone number on the receipt}
        {--footer= : The receipt footer, such as the returns wording}
        {--output= : Where to write the profile (default: storage/app/profiles/<shop>.json)}';

    protected $description = 'Make a shop profile for the installer to apply';

    public function handle(Registry $registry): int
    {
        $shop = $this->value('shop', 'Shop name', required: true);
        $branchName = $this->value('branch-name', 'Branch name', default: 'Main branch', required: true);
        $receiptName = $this->value('receipt-name', 'Shop name on the receipt (at most 40 characters)', default: Str::limit($shop, 40, ''), required: true);
        $address = $this->value('address', 'Address on the receipt (optional)');
        $phone = $this->value('phone', 'Phone on the receipt (optional)');
        $footer = $this->value('footer', 'Receipt footer, such as the returns wording (optional)');

        $count = max(1, min(20, (int) $this->option('terminals')));
        $drawers = array_filter(array_map('trim', explode(',', (string) $this->option('drawers'))));
        $printers = $this->printers();
        $terminals = [];
        for ($i = 1; $i <= $count; $i++) {
            $code = sprintf('T%02d', $i);
            $hasDrawer = in_array($code, $drawers, true);
            if ($this->option('drawers') === null && $this->input->isInteractive()) {
                $hasDrawer = confirm("Does counter {$code} have a cash drawer?", default: false);
            }
            $printer = $printers[$code] ?? null;
            if ($this->option('printers') === null && $this->input->isInteractive()) {
                $printer = $this->printer(text("Receipt printer's IP address for {$code} (leave blank if it has none yet)", validate: fn (string $v) => $v === '' || self::parsePrinter($v) !== null ? null : 'Type an IP address such as 192.168.1.21, or leave it blank.'));
            }
            $terminals[] = ['code' => $code, 'name' => "Counter {$i}", 'drawer' => $hasDrawer, 'kind' => 'counter', ...($printer === null ? [] : ['printer' => $printer])];
        }
        $devices = max(0, min(20, (int) $this->option('back-office')));
        for ($i = 1; $i <= $devices; $i++) {
            $terminals[] = ['code' => sprintf('D%02d', $i), 'name' => "Back office {$i}", 'drawer' => false, 'kind' => 'back_office'];
        }

        $document = Profile::document(
            shop: $shop,
            branchCode: (string) $this->option('branch-code'),
            branchName: $branchName,
            terminals: $terminals,
            receipt: array_filter(
                ['shop_name' => $receiptName, 'address' => $address, 'phone' => $phone, 'footer' => $footer],
                fn (?string $v) => $v !== null && $v !== '',
            ),
        );

        // Check it before writing, so a profile on disk is always one the installer will accept.
        /** @var array<string, mixed> $roundTrip */
        $roundTrip = json_decode((string) json_encode($document), true);
        Profile::fromArray($roundTrip, $registry);

        $output = $this->option('output');
        $path = is_string($output) && $output !== '' ? $output : storage_path('app/profiles/'.Str::slug($shop).'.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o750, true);
        }
        file_put_contents($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->components->info("The profile is at {$path}. It holds shop data: never commit it.");

        return self::SUCCESS;
    }

    /**
     * The printers given with --printers, by counter code.
     *
     * @return array<string, array{host: string, port: int}>
     */
    private function printers(): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $this->option('printers')))) as $pair) {
            [$code, $address] = array_pad(array_map('trim', explode('=', $pair, 2)), 2, '');
            $printer = self::parsePrinter($address);
            if (preg_match('/^T\d{2}$/', $code) !== 1 || $printer === null) {
                throw new \InvalidArgumentException("--printers: \"{$pair}\" is not a counter and a printer address, such as T01=192.168.1.21.");
            }
            $out[$code] = $printer;
        }

        return $out;
    }

    /** @return array{host: string, port: int}|null */
    private function printer(string $address): ?array
    {
        return $address === '' ? null : self::parsePrinter($address);
    }

    /**
     * A printer's address: an IP address or a name (the stand-in printer of a rehearsal is "printer"), with
     * port 9100 unless another is given.
     *
     * @return array{host: string, port: int}|null
     */
    public static function parsePrinter(string $address): ?array
    {
        if (preg_match('/^([A-Za-z0-9.-]{1,64})(?::(\d{1,5}))?$/', trim($address), $m) !== 1) {
            return null;
        }
        $port = isset($m[2]) ? (int) $m[2] : 9100;

        return $port >= 1 && $port <= 65535 ? ['host' => $m[1], 'port' => $port] : null;
    }

    private function value(string $option, string $label, string $default = '', bool $required = false): string
    {
        $value = $this->option($option);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (! $this->input->isInteractive()) {
            if ($required && $default === '') {
                throw new \InvalidArgumentException("--{$option} is required.");
            }

            return $default;
        }

        return text($label, default: $default, required: $required);
    }
}
