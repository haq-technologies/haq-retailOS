<?php

declare(strict_types=1);

namespace App\Sales;

use RuntimeException;

/** Internal: another request with the same key finished first; the transaction rolls back and returns it. */
final class DuplicateSale extends RuntimeException {}
