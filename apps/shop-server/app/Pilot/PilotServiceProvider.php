<?php

declare(strict_types=1);

namespace App\Pilot;

use Illuminate\Support\ServiceProvider;

/**
 * The Pilot module (Phase 5; D23, D56): the measured pilot's scorecard against the success thresholds of
 * spec section 14, the notebook's totals and each difference traced, and the owner's decision at gate G5.
 * It reads the records of the modules beneath it and writes only its own three append-only tables.
 */
class PilotServiceProvider extends ServiceProvider {}
