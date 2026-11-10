<?php

declare(strict_types=1);

namespace App\Shifts;

use RuntimeException;

/** A drawer action refused in plain words, for the counter to show. */
class DrawerRefused extends RuntimeException {}
