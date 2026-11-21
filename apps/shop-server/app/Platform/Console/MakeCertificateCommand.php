<?php

declare(strict_types=1);

namespace App\Platform\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Makes the shop's HTTPS certificate (spec section 4) [F24]: a local certificate authority, kept on the
 * server, issues a long-lived certificate for the server's name and fixed IP. Each counter trusts the
 * authority once, at install. The health page warns 60 days before the certificate expires; renewal is
 * this command with --renew. A copy of the authority is kept beside the backups; when a restore brings it
 * back in place of one a spare made for itself, the next run issues a certificate from it (D69).
 *
 * The authority may vouch only for the shop's own server: its name constraints permit the server's name,
 * localhost and the private address ranges, so a copied key cannot impersonate a bank or an email site
 * on the devices that trust it (D120). It lives in a folder of its own, which only this command and the
 * scheduler's copy beside the backups can reach, never the app that answers the counters (D121).
 */
#[AsCommand(name: 'shop:make-certificate')]
class MakeCertificateCommand extends Command
{
    private const CA_DAYS = 3650;

    private const SERVER_DAYS = 1825;

    /** Private IPv4 and IPv6 ranges and loopback, as address and mask: where a shop's server can be. */
    private const PRIVATE_RANGES = [
        '10.0.0.0/255.0.0.0', '172.16.0.0/255.240.0.0', '192.168.0.0/255.255.0.0', '127.0.0.0/255.0.0.0',
        'fc00::/fe00::', 'fe80::/ffc0::', '::1/ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff',
    ];

    /** A host name: letters, digits and hyphens in dot-separated labels (RFC 1123). */
    private const HOST_NAME = '/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/';

    protected $signature = 'shop:make-certificate
        {--name= : The server\'s local name, such as haq-server.local}
        {--ip= : The server\'s fixed IP address}
        {--ca-dir= : Where the certificate authority lives (default: organisation.ca_dir, storage/app/ca)}
        {--tls-dir= : Where nginx reads the server certificate (default: storage/app/tls)}
        {--move-from= : An older install\'s authority folder, moved into --ca-dir once (D121)}
        {--renew : Make a new server certificate even if the current one is still good}';

    protected $description = 'Make the local certificate authority and the server\'s HTTPS certificate';

    /** @var list<string> OpenSSL config files written for this run, removed at the end. */
    private array $configFiles = [];

    public function handle(): int
    {
        try {
            return $this->make();
        } finally {
            foreach ($this->configFiles as $file) {
                @unlink($file);
            }
        }
    }

    private function make(): int
    {
        $name = (string) $this->option('name');
        $ip = (string) $this->option('ip');
        // The name goes into the certificate's OpenSSL settings, so it must be a plain host name.
        if (preg_match(self::HOST_NAME, $name) !== 1 || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->components->error('Give the server\'s name (--name, such as haq-server.local) and its fixed IP address (--ip).');

            return self::INVALID;
        }
        $caDir = $this->directory('ca-dir', config()->string('organisation.ca_dir'), 0o700);
        $tlsDir = $this->directory('tls-dir', storage_path('app/tls'), 0o750);
        $from = $this->option('move-from');
        if (is_string($from) && $from !== '') {
            $this->moveAuthority($from, $caDir);
        }

        if (! $this->option('renew') && $this->stillGood($tlsDir, $caDir, $name, $ip)) {
            $this->publish($tlsDir, $caDir);
            $this->components->info('The server certificate is still good; nothing to change.');
            $this->warnIfUnconstrained($caDir);

            return self::SUCCESS;
        }

        [$caKey, $caCert, $new] = $this->authority($caDir, $name, $ip);
        $this->server($tlsDir, $name, $ip, $caKey, $caCert);
        copy("{$caDir}/ca.crt", "{$tlsDir}/ca.crt");
        $this->publish($tlsDir, $caDir);

        $this->components->info("Made the certificate for {$name} ({$ip}), valid for 5 years.");
        if ($new) {
            $this->line("Install {$tlsDir}/ca.crt as a trusted root on every counter, once.");
        } else {
            $this->line('It comes from the shop\'s own authority, which the counters already trust.');
            $this->warnIfUnconstrained($caDir);
        }

        return self::SUCCESS;
    }

    /**
     * The authority's thumbprint (SHA-1, as Windows shows it in the certificate's details), beside it for
     * the counters' setup page and `haq certificate`, so whoever trusts it on a counter can see it is the
     * shop's own (D59). Written on every run, so an older install gains it too. The server's certificate,
     * which is public, is copied beside the authority for the health page, which reads its expiry without
     * reaching the server's key (D122).
     */
    private function publish(string $tlsDir, string $caDir): void
    {
        $pem = @file_get_contents("{$tlsDir}/ca.crt");
        $hex = is_string($pem) ? openssl_x509_fingerprint($pem, 'sha1') : false;
        if (is_string($hex)) {
            $this->write("{$tlsDir}/ca.thumbprint", implode(' ', str_split(strtoupper($hex), 4))."\n", 0o644);
        }
        if (is_file("{$tlsDir}/server.key")) {
            chmod("{$tlsDir}/server.key", 0o600);
        }
        $server = @file_get_contents("{$tlsDir}/server.crt");
        if (is_string($server) && realpath($tlsDir) !== realpath($caDir)) {
            $this->write("{$caDir}/server.crt", $server, 0o644);
        }
    }

    /**
     * Moves an older install's authority into its own folder, once (D121). Until 21 November 2026 it lived
     * in the app's storage, where the app that answers the counters could read its key.
     */
    private function moveAuthority(string $from, string $caDir): void
    {
        $from = rtrim($from, '/');
        if (! is_file("{$from}/ca.key") || realpath($from) === realpath($caDir)) {
            return;
        }
        if (! is_file("{$caDir}/ca.key")) {
            foreach (['ca.key' => 0o600, 'ca.crt' => 0o644] as $file => $mode) {
                if (is_file("{$from}/{$file}")) {
                    $this->write("{$caDir}/{$file}", (string) file_get_contents("{$from}/{$file}"), $mode);
                }
            }
            $this->line('Moved the shop\'s authority out of the app\'s storage, to a folder of its own.');
        }
        if (hash_file('sha256', "{$from}/ca.key") === hash_file('sha256', "{$caDir}/ca.key")) {
            @unlink("{$from}/ca.key");
            @unlink("{$from}/ca.crt");
            @rmdir($from);
        }
    }

    private function warnIfUnconstrained(string $caDir): void
    {
        $parsed = openssl_x509_parse((string) @file_get_contents("{$caDir}/ca.crt"));
        if (is_array($parsed) && ! isset($parsed['extensions']['nameConstraints'])) {
            $this->components->warn('This shop\'s authority was made before 21 November 2026, and may vouch for any website (D120). '
                .'Before the shop trades, install it again as a new shop, so its counters trust an authority limited to this server.');
        }
    }

    /**
     * The authority, made the first time. The third value is true when it was just made.
     *
     * @return array{\OpenSSLAsymmetricKey, \OpenSSLCertificate, bool}
     */
    private function authority(string $dir, string $name, string $ip): array
    {
        if (is_file("{$dir}/ca.key") && is_file("{$dir}/ca.crt")) {
            $key = openssl_pkey_get_private((string) file_get_contents("{$dir}/ca.key"));
            $cert = openssl_x509_read((string) file_get_contents("{$dir}/ca.crt"));
            if ($key !== false && $cert !== false) {
                return [$key, $cert, false];
            }
        }

        $key = $this->newKey();
        $config = $this->config('ca', $name, $ip);
        $csr = openssl_csr_new(['commonName' => 'HAQ Retail OS shop authority', 'organizationName' => 'HAQ Technology'], $key, $config);
        if ($csr === false || $csr === true) {
            throw new RuntimeException('OpenSSL could not make the authority.');
        }
        $cert = openssl_csr_sign($csr, null, $key, self::CA_DAYS, $config, random_int(1, PHP_INT_MAX));
        if ($cert === false) {
            throw new RuntimeException('OpenSSL could not sign the authority.');
        }
        $this->write("{$dir}/ca.key", $this->exportKey($key), 0o600);
        openssl_x509_export($cert, $pem);
        $this->write("{$dir}/ca.crt", (string) $pem, 0o644);

        return [$key, $cert, true];
    }

    private function server(string $dir, string $name, string $ip, \OpenSSLAsymmetricKey $caKey, \OpenSSLCertificate $caCert): void
    {
        $key = $this->newKey();
        $config = $this->config('server', $name, $ip);
        $csr = openssl_csr_new(['commonName' => $name, 'organizationName' => 'HAQ Technology'], $key, $config);
        if ($csr === false || $csr === true) {
            throw new RuntimeException('OpenSSL could not make the server certificate.');
        }
        $cert = openssl_csr_sign($csr, $caCert, $caKey, self::SERVER_DAYS, $config, random_int(1, PHP_INT_MAX));
        if ($cert === false) {
            throw new RuntimeException('OpenSSL could not sign the server certificate.');
        }
        openssl_x509_export($cert, $pem);
        $this->write("{$dir}/server.crt", (string) $pem, 0o644);
        // Only nginx reads the key, and its master process runs as root, so the key is for its owner alone (D122).
        $this->write("{$dir}/server.key", $this->exportKey($key), 0o600);
    }

    /**
     * Still good: for this name and IP, more than 60 days from expiry, carrying its key identifiers, and
     * issued by the authority now in the authority's folder. After a restore brings the shop's authority back
     * in place of the one a spare made at install, the spare's certificate is not still good, and a new one
     * is issued from the shop's (D69). A certificate from before the key identifiers is issued again, from
     * the same authority, so the counters notice nothing (D122).
     */
    private function stillGood(string $tlsDir, string $caDir, string $name, string $ip): bool
    {
        $path = "{$tlsDir}/server.crt";
        if (! is_file($path)) {
            return false;
        }
        $pem = (string) file_get_contents($path);
        $parsed = openssl_x509_parse($pem);
        if ($parsed === false) {
            return false;
        }
        $names = (string) ($parsed['extensions']['subjectAltName'] ?? '');
        $expires = CarbonImmutable::createFromTimestamp((int) $parsed['validTo_time_t']);
        if (! str_contains($names, "DNS:{$name}") || ! str_contains($names, 'IP Address:'.$this->sanAddress($ip)) || ! $expires->isAfter(now()->addDays(60))) {
            return false;
        }
        if (! isset($parsed['extensions']['authorityKeyIdentifier'])) {
            return false;
        }
        if (! is_file("{$caDir}/ca.crt")) {
            return true; // No authority here to compare with; keep the certificate the counters already trust.
        }
        $authority = (string) file_get_contents("{$caDir}/ca.crt");

        return openssl_x509_verify($pem, $authority) === 1 && @file_get_contents("{$tlsDir}/ca.crt") === $authority;
    }

    /** The IP as OpenSSL prints it in a certificate's names: IPv6 in full, upper case, without shortening. */
    private function sanAddress(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }
        $packed = (string) inet_pton($ip);

        return implode(':', array_map(fn (string $h) => strtoupper(ltrim($h, '0') ?: '0'), str_split(bin2hex($packed), 4)));
    }

    private function newKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        return $key === false ? throw new RuntimeException('OpenSSL could not make a key.') : $key;
    }

    private function exportKey(\OpenSSLAsymmetricKey $key): string
    {
        openssl_pkey_export($key, $pem);

        return (string) $pem;
    }

    /**
     * The extensions for the authority or the server's certificate (D120, D122).
     *
     * @return array<string, string>
     */
    private function config(string $kind, string $name, string $ip): array
    {
        if ($kind === 'ca') {
            $permitted = ["DNS:{$name}", 'DNS:localhost', ...array_map(fn (string $r) => "IP:{$r}", self::PRIVATE_RANGES)];
            if (! $this->isPrivate($ip)) {
                // A fixed address outside the private ranges is still the server's own.
                $mask = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff' : '255.255.255.255';
                $permitted[] = "IP:{$ip}/{$mask}";
            }
            $extensions = "basicConstraints = critical, CA:true, pathlen:0\nkeyUsage = critical, keyCertSign, cRLSign\nsubjectKeyIdentifier = hash\n"
                .'nameConstraints = critical, '.implode(', ', array_map(fn (string $p) => "permitted;{$p}", array_unique($permitted)))."\n";
        } else {
            $extensions = "basicConstraints = critical, CA:false\nkeyUsage = critical, digitalSignature\nextendedKeyUsage = serverAuth\n"
                ."subjectKeyIdentifier = hash\nauthorityKeyIdentifier = keyid:always\n"
                ."subjectAltName = DNS:{$name}, DNS:localhost, IP:{$ip}, IP:127.0.0.1\n";
        }
        // The request carries the same extensions but the authority's key identifier, which only signing,
        // with the authority at hand, can give.
        $request = implode("\n", array_filter(explode("\n", $extensions), fn (string $line) => ! str_starts_with($line, 'authorityKeyIdentifier')));
        $path = storage_path('framework/cache/openssl-'.bin2hex(random_bytes(12)).'.cnf');
        file_put_contents($path, "[req]\ndistinguished_name = dn\n[dn]\n[ext]\n{$extensions}[reqext]\n{$request}\n");
        $this->configFiles[] = $path;

        return ['config' => $path, 'x509_extensions' => 'ext', 'req_extensions' => 'reqext', 'digest_alg' => 'sha256'];
    }

    /** Whether the address falls in one of the ranges the authority already permits. */
    private function isPrivate(string $ip): bool
    {
        $address = inet_pton($ip);
        foreach (self::PRIVATE_RANGES as $range) {
            [$network, $mask] = explode('/', $range);
            $network = inet_pton($network);
            $mask = inet_pton($mask);
            if (is_string($address) && is_string($network) && is_string($mask) && strlen($address) === strlen($network)
                && ($address & $mask) === ($network & $mask)) {
                return true;
            }
        }

        return false;
    }

    private function directory(string $option, string $default, int $mode): string
    {
        $value = $this->option($option);
        $dir = is_string($value) && $value !== '' ? $value : $default;
        if (! is_dir($dir) && ! mkdir($dir, $mode, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}.");
        }

        return rtrim($dir, '/');
    }

    /** Written with no access for others from the start, then given its mode and put in place at once. */
    private function write(string $path, string $contents, int $mode): void
    {
        $old = umask(0o077);
        try {
            file_put_contents($path.'.tmp', $contents);
        } finally {
            umask($old);
        }
        chmod($path.'.tmp', $mode);
        rename($path.'.tmp', $path);
    }
}
