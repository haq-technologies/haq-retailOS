<?php

declare(strict_types=1);

namespace App\Sales;

use RuntimeException;

/**
 * A sale the server will not finish, with a message the cashier can act on (spec section 15). Nothing is
 * written, and no receipt number is used.
 */
class SaleRefused extends RuntimeException {}
