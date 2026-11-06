<?php

declare(strict_types=1);

namespace App\Platform\Database\Mutability;

use RuntimeException;

final class UnclassifiedTable extends RuntimeException
{
    /**
     * @param  list<string>  $tables
     */
    public static function tables(array $tables): self
    {
        return new self(
            'These tables are not in the mutability matrix (app/Platform/Database/Mutability/Matrix.php): '
            .implode(', ', $tables).'. Classify every new table before it can be migrated (spec section 5).'
        );
    }
}
