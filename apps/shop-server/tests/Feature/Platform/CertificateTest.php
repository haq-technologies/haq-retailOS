<?php

declare(strict_types=1);
use Illuminate\Testing\PendingCommand;

/*
 * The shop's HTTPS certificate (spec section 4) [F24]: a local authority issues a long-lived certificate
 * for the server's name and fixed IP.
 */

beforeEach(function () {
    $this->dir = storage_path('framework/testing/tls-'.getmypid());
    @mkdir($this->dir, 0o750, true);
});

afterEach(function () {
    foreach (glob($this->dir.'/{ca,tls}/*', GLOB_BRACE) ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->dir.'/ca');
    @rmdir($this->dir.'/tls');
    @rmdir($this->dir);
});

function makeCertificate(string $dir, array $extra = []): PendingCommand
{
    return test()->artisan('shop:make-certificate', [
        '--name' => 'haq-server.local', '--ip' => '192.168.1.10',
        '--ca-dir' => $dir.'/ca', '--tls-dir' => $dir.'/tls', ...$extra,
    ]);
}

it('issues a certificate for the server\'s name and IP, signed by the shop\'s authority', function () {
    makeCertificate($this->dir)->assertSuccessful();

    $server = openssl_x509_parse((string) file_get_contents($this->dir.'/tls/server.crt'));
    $ca = openssl_x509_parse((string) file_get_contents($this->dir.'/tls/ca.crt'));

    expect($server['extensions']['subjectAltName'])->toContain('DNS:haq-server.local')->toContain('IP Address:192.168.1.10');
    expect($server['issuer']['CN'])->toBe('HAQ Retail OS shop authority');
    expect($ca['extensions']['basicConstraints'])->toContain('CA:TRUE');
    // Valid for about five years; the health page warns 60 days ahead.
    expect($server['validTo_time_t'] - time())->toBeGreaterThan(4 * 365 * 86400);

    $chain = openssl_x509_checkpurpose((string) file_get_contents($this->dir.'/tls/server.crt'), X509_PURPOSE_SSL_SERVER, [$this->dir.'/tls/ca.crt']);
    expect($chain)->toBeTrue();
    expect(decoct(fileperms($this->dir.'/ca/ca.key') & 0o777))->toBe('600');

    // The thumbprint the counters' setup page shows, as Windows shows it in the certificate's details (D59).
    $thumb = strtoupper((string) openssl_x509_fingerprint((string) file_get_contents($this->dir.'/tls/ca.crt'), 'sha1'));
    expect(file_get_contents($this->dir.'/tls/ca.thumbprint'))->toBe(implode(' ', str_split($thumb, 4))."\n");
});

it('keeps a certificate that is still good, and renews on request with the same authority', function () {
    makeCertificate($this->dir)->assertSuccessful();
    $first = (string) file_get_contents($this->dir.'/tls/server.crt');
    $ca = (string) file_get_contents($this->dir.'/ca/ca.crt');

    unlink($this->dir.'/tls/ca.thumbprint');
    makeCertificate($this->dir)->expectsOutputToContain('still good')->assertSuccessful();
    expect(file_get_contents($this->dir.'/tls/server.crt'))->toBe($first);
    // An install from before the thumbprint gains it on its next run.
    expect(is_file($this->dir.'/tls/ca.thumbprint'))->toBeTrue();

    makeCertificate($this->dir, ['--renew' => true])->assertSuccessful();
    expect(file_get_contents($this->dir.'/tls/server.crt'))->not->toBe($first);
    expect(file_get_contents($this->dir.'/ca/ca.crt'))->toBe($ca);
});

it('makes a new certificate when the server\'s IP changes', function () {
    makeCertificate($this->dir)->assertSuccessful();
    makeCertificate($this->dir, ['--ip' => '192.168.1.20'])->assertSuccessful();

    $server = openssl_x509_parse((string) file_get_contents($this->dir.'/tls/server.crt'));
    expect($server['extensions']['subjectAltName'])->toContain('IP Address:192.168.1.20');
});

it('refuses without a name and a valid IP', function () {
    $this->artisan('shop:make-certificate', ['--name' => 'x', '--ip' => 'not-an-ip'])->assertExitCode(2);
});
