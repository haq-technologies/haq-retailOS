<?php

declare(strict_types=1);

namespace App\Identity\Console;

/**
 * A PIN given to a command rather than asked for (AU9, D99). --pin-stdin reads it as the first line of standard
 * input, so it never stands in a command line that other programs can list: the Windows helper sends it so,
 * through the runner, `haq` and `docker compose exec`. --pin stays for scripted test installs.
 */
trait GivenPin
{
    /** Tests only: where --pin-stdin reads from. */
    public static string $pinInput = 'php://stdin';

    /** The PIN given with --pin-stdin or --pin, or null when it is to be asked for. */
    private function givenPin(): ?string
    {
        if ($this->option('pin-stdin') === true) {
            $stream = fopen(self::$pinInput, 'r');
            $line = $stream === false ? false : fgets($stream);
            if ($stream !== false) {
                fclose($stream);
            }

            return is_string($line) ? trim($line) : '';
        }
        $pin = $this->option('pin');

        return is_string($pin) && $pin !== '' ? $pin : null;
    }
}
