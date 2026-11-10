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
 * this command with --renew.
 */
#[AsCommand(name: 'shop:make-certificate')]
class MakeCertificateCommand extends Command
{
    private const CA_DAYS = 3650;

    private const SERVER_DAYS = 1825;

    protected $signature = 'shop:make-certificate
        {--name= : The server\'s local name, such as haq-server.local}
        {--ip= : The server\'s fixed IP address}
        {--ca-dir= : Where the certificate authority lives (default: storage/app/ca)}
        {--tls-dir= : Where nginx reads the server certificate (default: storage/app/tls)}
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
        if ($name === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->components->error('Give the server\'s name (--name) and its fixed IP address (--ip).');

            return self::INVALID;
        }
        $caDir = $this->directory('ca-dir', 'app/ca');
        $tlsDir = $this->directory('tls-dir', 'app/tls');

        if (! $this->option('renew') && $this->stillGood("{$tlsDir}/server.crt", $name, $ip)) {
            $this->thumbprint($tlsDir);
            $this->components->info('The server certificate is still good; nothing to change.');

            return self::SUCCESS;
        }

        [$caKey, $caCert] = $this->authority($caDir);
        $this->server($tlsDir, $name, $ip, $caKey, $caCert);
        copy("{$caDir}/ca.crt", "{$tlsDir}/ca.crt");
        $this->thumbprint($tlsDir);

        $this->components->info("Made the certificate for {$name} ({$ip}), valid for 5 years.");
        $this->line("Install {$tlsDir}/ca.crt as a trusted root on every counter, once.");

        return self::SUCCESS;
    }

    /**
     * The authority's thumbprint (SHA-1, as Windows shows it in the certificate's details), beside it for
     * the counters' setup page and `haq certificate`, so whoever trusts it on a counter can see it is the
     * shop's own (D59). Written on every run, so an older install gains it too.
     */
    private function thumbprint(string $tlsDir): void
    {
        $pem = @file_get_contents("{$tlsDir}/ca.crt");
        $hex = is_string($pem) ? openssl_x509_fingerprint($pem, 'sha1') : false;
        if (is_string($hex)) {
            $this->write("{$tlsDir}/ca.thumbprint", implode(' ', str_split(strtoupper($hex), 4))."\n", 0o644);
        }
    }

    /**
     * @return array{\OpenSSLAsymmetricKey, \OpenSSLCertificate}
     */
    private function authority(string $dir): array
    {
        if (is_file("{$dir}/ca.key") && is_file("{$dir}/ca.crt")) {
            $key = openssl_pkey_get_private((string) file_get_contents("{$dir}/ca.key"));
            $cert = openssl_x509_read((string) file_get_contents("{$dir}/ca.crt"));
            if ($key !== false && $cert !== false) {
                return [$key, $cert];
            }
        }

        $key = $this->newKey();
        $csr = openssl_csr_new(['commonName' => 'HAQ Retail OS shop authority', 'organizationName' => 'HAQ Technology'], $key, $this->config('ca'));
        if ($csr === false || $csr === true) {
            throw new RuntimeException('OpenSSL could not make the authority.');
        }
        $cert = openssl_csr_sign($csr, null, $key, self::CA_DAYS, $this->config('ca'), random_int(1, PHP_INT_MAX));
        if ($cert === false) {
            throw new RuntimeException('OpenSSL could not sign the authority.');
        }
        $this->write("{$dir}/ca.key", $this->exportKey($key), 0o600);
        openssl_x509_export($cert, $pem);
        $this->write("{$dir}/ca.crt", (string) $pem, 0o644);

        return [$key, $cert];
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
        // nginx's workers read the key; the TLS volume is mounted only into nginx and this command.
        $this->write("{$dir}/server.key", $this->exportKey($key), 0o644);
    }

    private function stillGood(string $path, string $name, string $ip): bool
    {
        if (! is_file($path)) {
            return false;
        }
        $parsed = openssl_x509_parse((string) file_get_contents($path));
        if ($parsed === false) {
            return false;
        }
        $names = (string) ($parsed['extensions']['subjectAltName'] ?? '');
        $expires = CarbonImmutable::createFromTimestamp((int) $parsed['validTo_time_t']);

        return str_contains($names, "DNS:{$name}") && str_contains($names, "IP Address:{$ip}") && $expires->isAfter(now()->addDays(60));
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
     * @return array<string, string>
     */
    private function config(string $kind, string $name = '', string $ip = ''): array
    {
        $extensions = $kind === 'ca'
            ? "basicConstraints = critical, CA:true, pathlen:0\nkeyUsage = critical, keyCertSign, cRLSign\nsubjectKeyIdentifier = hash\n"
            : "basicConstraints = critical, CA:false\nkeyUsage = critical, digitalSignature, keyEncipherment\nextendedKeyUsage = serverAuth\n"
                ."subjectAltName = DNS:{$name}, DNS:localhost, IP:{$ip}, IP:127.0.0.1\n";
        $path = storage_path('framework/cache/openssl-'.bin2hex(random_bytes(12)).'.cnf');
        file_put_contents($path, "[req]\ndistinguished_name = dn\n[dn]\n[ext]\n{$extensions}");
        $this->configFiles[] = $path;

        return ['config' => $path, 'x509_extensions' => 'ext', 'req_extensions' => 'ext', 'digest_alg' => 'sha256'];
    }

    private function directory(string $option, string $default): string
    {
        $value = $this->option($option);
        $dir = is_string($value) && $value !== '' ? $value : storage_path($default);
        if (! is_dir($dir) && ! mkdir($dir, 0o750, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}.");
        }

        return rtrim($dir, '/');
    }

    private function write(string $path, string $contents, int $mode): void
    {
        file_put_contents($path, $contents);
        chmod($path, $mode);
    }
}
