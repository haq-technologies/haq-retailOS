<?php

declare(strict_types=1);

namespace App\Backups;

use RuntimeException;

/**
 * The status folder the backup service and the app share on the backup disk (spec section 9): backup.json
 * and restore.json from the backup service; verified-<label>.json, epochs.json and keys/ from the app.
 */
final readonly class Status
{
    public function __construct(public ?string $directory) {}

    public static function fromConfig(): self
    {
        $dir = config('backups.status_dir');

        return new self(is_string($dir) && $dir !== '' ? rtrim($dir, '/') : null);
    }

    public function enabled(): bool
    {
        return $this->directory !== null && is_dir($this->directory);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $name): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $path = $this->directory.'/'.$name;
        if (! is_file($path)) {
            return null;
        }
        /** @var array<string, mixed>|null $data */
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function write(string $name, array $data): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('There is no backup status folder.');
        }
        $path = $this->directory.'/'.$name;
        file_put_contents($path.'.tmp', json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        rename($path.'.tmp', $path);
    }

    public function path(string $name): string
    {
        return ($this->directory ?? '').'/'.$name;
    }
}
