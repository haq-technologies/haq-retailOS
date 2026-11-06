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
        $terminals = [];
        for ($i = 1; $i <= $count; $i++) {
            $code = sprintf('T%02d', $i);
            $hasDrawer = in_array($code, $drawers, true);
            if ($this->option('drawers') === null && $this->input->isInteractive()) {
                $hasDrawer = confirm("Does counter {$code} have a cash drawer?", default: false);
            }
            $terminals[] = ['code' => $code, 'name' => "Counter {$i}", 'drawer' => $hasDrawer];
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
