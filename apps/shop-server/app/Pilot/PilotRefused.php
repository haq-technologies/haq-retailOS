<?php

declare(strict_types=1);

namespace App\Pilot;

use RuntimeException;

/** A measured-pilot record that cannot be written, with the reason in words the person can act on. */
final class PilotRefused extends RuntimeException {}
