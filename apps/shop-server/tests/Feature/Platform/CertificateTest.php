<?php

declare(strict_types=1);
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
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
    foreach (glob($this->dir.'/{ca,tls,old-ca}/*', GLOB_BRACE) ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->dir.'/ca');
    @rmdir($this->dir.'/tls');
    @rmdir($this->dir.'/old-ca');
    @rmdir($this->dir);
});

/**
 * Signs a certificate for $name with the authority in $caDir, as someone holding its key could, with or
 * without the key identifiers. Returns the certificate's path.
 */
function signWithAuthority(string $caDir, string $name, string $out, bool $keyIds = true): string
{
    $config = $out.'.cnf';
    file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[ext]\nbasicConstraints = critical, CA:false\n"
        ."keyUsage = critical, digitalSignature\nextendedKeyUsage = serverAuth\nsubjectAltName = DNS:{$name}, IP:192.168.1.10\n"
        .($keyIds ? "subjectKeyIdentifier = hash\nauthorityKeyIdentifier = keyid:always\n" : ''));
    $options = ['config' => $config, 'x509_extensions' => 'ext', 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $csr = openssl_csr_new(['commonName' => $name], $key, $options);
    $caKey = openssl_pkey_get_private((string) file_get_contents($caDir.'/ca.key'));
    $cert = openssl_csr_sign($csr, (string) file_get_contents($caDir.'/ca.crt'), $caKey, 365, $options, random_int(1, PHP_INT_MAX));
    openssl_x509_export($cert, $pem);
    file_put_contents($out, $pem);
    unlink($config);

    return $out;
}

/** OpenSSL's own check of a certificate against the shop's authority, as a counter's browser makes it. */
function verifyAgainst(string $ca, string $cert, bool $strict = false): ProcessResult
{
    return Process::run(['openssl', 'verify', ...($strict ? ['-x509_strict'] : []), '-CAfile', $ca, $cert]);
}

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

it('issues a new certificate when the authority is replaced, as a restore onto a spare does (D69)', function () {
    makeCertificate($this->dir)->expectsOutputToContain('as a trusted root on every counter')->assertSuccessful();
    $spare = (string) file_get_contents($this->dir.'/tls/server.crt');

    // The restore puts the shop's authority in place of the one this spare made for itself.
    $shop = storage_path('framework/testing/tls-shop-'.getmypid());
    makeCertificate($shop)->assertSuccessful();
    copy($shop.'/ca/ca.key', $this->dir.'/ca/ca.key');
    copy($shop.'/ca/ca.crt', $this->dir.'/ca/ca.crt');
    $authority = (string) file_get_contents($shop.'/ca/ca.crt');
    foreach (glob($shop.'/{ca,tls}/*', GLOB_BRACE) ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($shop.'/ca');
    @rmdir($shop.'/tls');
    @rmdir($shop);

    makeCertificate($this->dir)->expectsOutputToContain('the counters already trust')->doesntExpectOutputToContain('still good')->assertSuccessful();
    $server = (string) file_get_contents($this->dir.'/tls/server.crt');
    expect($server)->not->toBe($spare);
    expect(openssl_x509_verify($server, $authority))->toBe(1);
    expect(file_get_contents($this->dir.'/tls/ca.crt'))->toBe($authority);
    // From then on it is still good.
    makeCertificate($this->dir)->expectsOutputToContain('still good')->assertSuccessful();
});

it('refuses without a name and a valid IP', function () {
    $this->artisan('shop:make-certificate', ['--name' => 'x', '--ip' => 'not-an-ip'])->assertExitCode(2);
});

it('limits the authority to the server\'s own names and the private address ranges (D120)', function () {
    makeCertificate($this->dir)->assertSuccessful();

    $ca = openssl_x509_parse((string) file_get_contents($this->dir.'/ca/ca.crt'));
    expect($ca['extensions']['nameConstraints'])
        ->toContain('DNS:haq-server.local')->toContain('DNS:localhost')
        ->toContain('IP:10.0.0.0/255.0.0.0')->toContain('IP:172.16.0.0/255.240.0.0')
        ->toContain('IP:192.168.0.0/255.255.0.0')->toContain('IP:127.0.0.0/255.0.0.0');

    // The server's own certificate passes, even OpenSSL's strict check (D122).
    expect(verifyAgainst($this->dir.'/ca/ca.crt', $this->dir.'/tls/server.crt', strict: true)->successful())->toBeTrue();

    // A certificate the authority signs for another website is refused, so a copied key cannot pass for a
    // bank or an email site on the devices that trust the shop's authority.
    $rogue = signWithAuthority($this->dir.'/ca', 'bank.example.com', $this->dir.'/tls/rogue.crt');
    $result = verifyAgainst($this->dir.'/ca/ca.crt', $rogue);
    expect($result->successful())->toBeFalse();
    expect($result->output().$result->errorOutput())->toContain('permitted subtree violation');
});

it('permits a fixed address outside the private ranges, the server\'s own', function () {
    makeCertificate($this->dir, ['--ip' => '41.58.10.10'])->assertSuccessful();

    $ca = openssl_x509_parse((string) file_get_contents($this->dir.'/ca/ca.crt'));
    expect($ca['extensions']['nameConstraints'])->toContain('IP:41.58.10.10/255.255.255.255');
    expect(verifyAgainst($this->dir.'/ca/ca.crt', $this->dir.'/tls/server.crt', strict: true)->successful())->toBeTrue();
});

it('keeps the server\'s key for its owner, and a copy of the certificate beside the authority for the health page (D122)', function () {
    makeCertificate($this->dir)->assertSuccessful();

    expect(decoct(fileperms($this->dir.'/tls/server.key') & 0o777))->toBe('600');
    expect(file_get_contents($this->dir.'/ca/server.crt'))->toBe(file_get_contents($this->dir.'/tls/server.crt'));
    $server = openssl_x509_parse((string) file_get_contents($this->dir.'/tls/server.crt'));
    expect($server['extensions'])->toHaveKeys(['subjectKeyIdentifier', 'authorityKeyIdentifier']);
    expect($server['extensions']['keyUsage'])->toBe('Digital Signature');
});

it('issues a certificate from before the key identifiers again, from the same authority', function () {
    makeCertificate($this->dir)->assertSuccessful();
    $authority = (string) file_get_contents($this->dir.'/ca/ca.crt');
    // As shop:make-certificate issued it until 21 November 2026, with the key readable by all.
    $old = signWithAuthority($this->dir.'/ca', 'haq-server.local', $this->dir.'/tls/server.crt', keyIds: false);
    chmod($this->dir.'/tls/server.key', 0o644);

    makeCertificate($this->dir)->doesntExpectOutputToContain('still good')->expectsOutputToContain('the counters already trust')->assertSuccessful();

    $server = openssl_x509_parse((string) file_get_contents($old));
    expect($server['extensions'])->toHaveKey('authorityKeyIdentifier');
    expect(openssl_x509_verify((string) file_get_contents($old), $authority))->toBe(1);
    expect(file_get_contents($this->dir.'/ca/ca.crt'))->toBe($authority);
    expect(decoct(fileperms($this->dir.'/tls/server.key') & 0o777))->toBe('600');
});

it('moves an older install\'s authority out of the app\'s storage, once (D121)', function () {
    // Until 21 November 2026 the authority lived in the app's storage.
    makeCertificate($this->dir, ['--ca-dir' => $this->dir.'/old-ca'])->assertSuccessful();
    $authority = (string) file_get_contents($this->dir.'/old-ca/ca.crt');
    $key = (string) file_get_contents($this->dir.'/old-ca/ca.key');

    makeCertificate($this->dir, ['--move-from' => $this->dir.'/old-ca'])->expectsOutputToContain('Moved the shop\'s authority')->assertSuccessful();

    expect(file_get_contents($this->dir.'/ca/ca.key'))->toBe($key);
    expect(file_get_contents($this->dir.'/ca/ca.crt'))->toBe($authority);
    expect(decoct(fileperms($this->dir.'/ca/ca.key') & 0o777))->toBe('600');
    expect(is_file($this->dir.'/old-ca/ca.key'))->toBeFalse();
    // The counters keep trusting the same authority.
    expect(file_get_contents($this->dir.'/tls/ca.crt'))->toBe($authority);

    // Run again, there is nothing left to move.
    makeCertificate($this->dir, ['--move-from' => $this->dir.'/old-ca'])->doesntExpectOutputToContain('Moved')->assertSuccessful();
});

it('warns when the authority predates its name constraints', function () {
    makeCertificate($this->dir)->assertSuccessful();
    // An authority as shop:make-certificate made it until 21 November 2026: no name constraints.
    $config = $this->dir.'/old.cnf';
    file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[ext]\nbasicConstraints = critical, CA:true, pathlen:0\nkeyUsage = critical, keyCertSign, cRLSign\nsubjectKeyIdentifier = hash\n");
    $options = ['config' => $config, 'x509_extensions' => 'ext', 'digest_alg' => 'sha256'];
    $key = openssl_pkey_get_private((string) file_get_contents($this->dir.'/ca/ca.key'));
    $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'HAQ Retail OS shop authority'], $key, $options), null, $key, 3650, $options, 7);
    openssl_x509_export($cert, $pem);
    file_put_contents($this->dir.'/ca/ca.crt', $pem);
    unlink($config);

    makeCertificate($this->dir)->expectsOutputToContain('made before 21 November 2026')->assertSuccessful();
});

it('refuses a name that is not a plain host name, since it goes into the certificate\'s settings', function () {
    $this->artisan('shop:make-certificate', ['--name' => "haq-server.local\nnameConstraints = permitted;DNS:", '--ip' => '192.168.1.10',
        '--ca-dir' => $this->dir.'/ca', '--tls-dir' => $this->dir.'/tls'])->assertExitCode(2);
    expect(is_file($this->dir.'/ca/ca.key'))->toBeFalse();
});
