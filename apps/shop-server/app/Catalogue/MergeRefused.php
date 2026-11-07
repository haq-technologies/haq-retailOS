<?php

declare(strict_types=1);

namespace App\Catalogue;

use RuntimeException;

/** A merge, an undo or a review answer the catalogue refuses, with a message the person can act on. */
final class MergeRefused extends RuntimeException {}
