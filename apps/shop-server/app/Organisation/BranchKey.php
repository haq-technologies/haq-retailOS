<?php

declare(strict_types=1);

namespace App\Organisation;

use App\Organisation\Models\Branch;
use Illuminate\Support\Facades\DB;
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

    /** Where the key file lives (it may not exist yet). */
    public function path(): string
    {
        return $this->directory.'/branch.key';
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
        $path = $this->path();
        if (is_file($path)) {
            return $this->read($path);
        }

        // Two counters may finish their first sales at once, in two processes or containers. Only one may make
        // the key: if each made its own, the last one written would win, and every epoch the other had signed
        // would no longer verify (D84). The lock is PostgreSQL's, which every container shares, and it is held to
        // the end of the transaction (the sale's own, when a sale signs first), so it is never left behind.
        return DB::transaction(function () use ($path): string {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['branch-key']);
            if (is_file($path)) {
                return $this->read($path); // another process made it while this one waited
            }
            // Made by one process alone, now; a folder some other process made in the meantime is fine too.
            if (! is_dir($this->directory) && ! @mkdir($this->directory, 0o700, true) && ! is_dir($this->directory)) {
                throw new RuntimeException("Cannot create {$this->directory}.");
            }
            $pair = sodium_crypto_sign_keypair();
            // Written whole before it appears, so a reader never sees half a key.
            $tmp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
            file_put_contents($tmp, base64_encode($pair));
            chmod($tmp, 0o600);
            rename($tmp, $path);
            $this->record($pair);

            return $pair;
        });
    }

    /**
     * @return non-empty-string
     */
    private function read(string $path): string
    {
        $pair = base64_decode((string) file_get_contents($path), true);
        if ($pair === false || $pair === '' || strlen($pair) !== SODIUM_CRYPTO_SIGN_KEYPAIRBYTES) {
            throw new RuntimeException("The branch key at {$path} is damaged.");
        }
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
