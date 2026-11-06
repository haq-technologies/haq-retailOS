<?php

declare(strict_types=1);

namespace App\Organisation;

use App\Organisation\Models\Branch;
use RuntimeException;

/**
 * The branch's own signing key (spec section 4) [S19]: an Ed25519 key pair, made once per branch and kept
 * on the server. It signs epoch records, so a restore's new epoch is tamper-evident; the public half is
 * stored on the branch so anyone can check a signature. Tamper-evident, not proof against root.
 */
final class BranchKey
{
    public function __construct(private readonly string $directory) {}

    public function sign(string $message): string
    {
        return base64_encode(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($this->keyPair())));
    }

    public function verify(string $message, string $signature, string $publicKey): bool
    {
        $signatureBytes = base64_decode($signature, true);
        $keyBytes = base64_decode($publicKey, true);
        if ($signatureBytes === false || $keyBytes === false || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($keyBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signatureBytes, $message, $keyBytes);
    }

    /** The public half, base64; recorded on the local branch the first time the key is made. */
    public function publicKey(): string
    {
        return base64_encode(sodium_crypto_sign_publickey($this->keyPair()));
    }

    /**
     * @return non-empty-string
     */
    private function keyPair(): string
    {
        $path = $this->directory.'/branch.key';
        if (is_file($path)) {
            $pair = base64_decode((string) file_get_contents($path), true);
            if ($pair === false || $pair === '' || strlen($pair) !== SODIUM_CRYPTO_SIGN_KEYPAIRBYTES) {
                throw new RuntimeException("The branch key at {$path} is damaged.");
            }
            $this->record($pair);

            return $pair;
        }

        if (! is_dir($this->directory) && ! mkdir($this->directory, 0o700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException("Cannot create {$this->directory}.");
        }
        $pair = sodium_crypto_sign_keypair();
        file_put_contents($path, base64_encode($pair));
        chmod($path, 0o600);
        $this->record($pair);

        return $pair;
    }

    /**
     * Records the public half on the local branch, if it is not there yet.
     *
     * @param  non-empty-string  $pair
     */
    private function record(string $pair): void
    {
        $public = base64_encode(sodium_crypto_sign_publickey($pair));
        $branch = Branch::query()->where('is_local', true)->first();
        if ($branch !== null && $branch->getAttribute('public_key') !== $public) {
            $branch->forceFill(['public_key' => $public])->save();
        }
    }
}
