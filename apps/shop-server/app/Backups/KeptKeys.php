<?php

declare(strict_types=1);

namespace App\Backups;

use App\Organisation\BranchKey;

/**
 * What a restore elsewhere needs beside the database (spec section 9) [S14, S19; D69]: the branch's signing
 * key, the shop's certificate authority and the app key. They are kept on the backup disk, and so on the
 * off-site drives, so after a restore onto a spare the counters trust the new server (its certificate comes
 * from the same authority) and stay enrolled (the same app key opens their device cookies).
 *
 * A copy is written once and never replaced. These keys never change on a working server, so a copy that
 * differs belongs to the server whose backups these are: a spare installed beside them must leave it alone
 * until its restore brings it back.
 */
final readonly class KeptKeys
{
    public function __construct(private Status $status, private BranchKey $branchKey) {}

    public static function fromConfig(): self
    {
        return new self(Status::fromConfig(), app(BranchKey::class));
    }

    /**
     * Keeps this server's keys on the backup disk. Returns the names of the copies that differ from this
     * server's own: they belong to the server whose backups these are.
     *
     * @return list<string>
     */
    public function keep(): array
    {
        if (! $this->status->enabled()) {
            return [];
        }
        $differ = [];
        foreach ($this->own() as $name => $contents) {
            $copy = $this->status->path("keys/{$name}");
            if (! is_file($copy)) {
                $this->write($copy, $contents, 0o600);
            } elseif (! hash_equals(hash('sha256', (string) file_get_contents($copy)), hash('sha256', $contents))) {
                $differ[] = $name;
            }
        }

        return $differ;
    }

    /**
     * After a restore: brings back the branch key when this machine has none, and the shop's authority in
     * place of one a spare made for itself at install. The app key lives in the server's .env, which
     * deploy/restore.sh sets from the copy before the app starts; a key that still differs is a warning.
     *
     * @return array{done: list<string>, warnings: list<string>}
     */
    public function bringBack(): array
    {
        $done = [];
        $warnings = [];
        if (! $this->status->enabled()) {
            return ['done' => $done, 'warnings' => $warnings];
        }

        $key = $this->branchKey->path();
        $copy = $this->status->path('keys/branch.key');
        if (! is_file($key) && is_file($copy)) {
            $this->write($key, (string) file_get_contents($copy), 0o600);
            $done[] = 'The branch key came back from the backup disk.';
        }

        $caKey = $this->status->path('keys/ca.key');
        $caCert = $this->status->path('keys/ca.crt');
        $dir = self::authorityDir();
        if (is_file($caKey) && is_file($caCert) && ! $this->same($caCert, "{$dir}/ca.crt")) {
            $this->write("{$dir}/ca.key", (string) file_get_contents($caKey), 0o600);
            $this->write("{$dir}/ca.crt", (string) file_get_contents($caCert), 0o644);
            $done[] = 'The shop\'s certificate authority came back from the backup disk: the counters trust this server as before.';
        } elseif (! is_file($caCert)) {
            $warnings[] = 'There is no certificate authority on the backup disk, so each counter must trust this server\'s certificate again (sudo haq certificate).';
        }

        $appKey = $this->status->path('keys/app.key');
        $current = config('app.key');
        if (is_file($appKey) && (! is_string($current) || trim((string) file_get_contents($appKey)) !== $current)) {
            $warnings[] = 'This server\'s app key differs from the one on the backup disk, so every counter must enrol again. deploy/restore.sh puts it back.';
        }

        return ['done' => $done, 'warnings' => $warnings];
    }

    public static function authorityDir(): string
    {
        return rtrim(config()->string('organisation.ca_dir'), '/');
    }

    /**
     * This server's own keys, by their name on the backup disk.
     *
     * @return array<string, string>
     */
    private function own(): array
    {
        $own = [];
        $branch = $this->branchKey->path();
        if (is_file($branch)) {
            $own['branch.key'] = (string) file_get_contents($branch);
        }
        $dir = self::authorityDir();
        if (is_file("{$dir}/ca.key") && is_file("{$dir}/ca.crt")) {
            $own['ca.key'] = (string) file_get_contents("{$dir}/ca.key");
            $own['ca.crt'] = (string) file_get_contents("{$dir}/ca.crt");
        }
        $appKey = config('app.key');
        if (is_string($appKey) && $appKey !== '') {
            $own['app.key'] = $appKey."\n";
        }

        return $own;
    }

    private function same(string $a, string $b): bool
    {
        return is_file($a) && is_file($b) && hash_file('sha256', $a) === hash_file('sha256', $b);
    }

    private function write(string $path, string $contents, int $mode): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o700, true);
        }
        file_put_contents($path.'.tmp', $contents);
        chmod($path.'.tmp', $mode);
        rename($path.'.tmp', $path);
    }
}
