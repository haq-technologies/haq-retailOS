<?php

declare(strict_types=1);

namespace App\Platform\Database\Mutability;

use InvalidArgumentException;

/**
 * One row of the mutability matrix: a table and how it may change.
 */
final readonly class Rule
{
    /**
     * @param  list<string>  $columns  For SetOnce and MutableColumns: the columns that may change.
     * @param  string|null  $lockColumn  For MutableWhileOpen: the column that says whether the row is closed.
     * @param  list<string>  $lockedValues  For MutableWhileOpen: the values that close the row.
     * @param  list<string>  $fixed  For Mutable and NeverDeleted: columns that never change once written,
     *                               such as permanent codes and seed IDs.
     * @param  list<string>  $increasing  For MutableColumns: columns that may only grow, such as a receipt
     *                                    counter, so a number can never be handed out twice.
     */
    public function __construct(
        public string $table,
        public Kind $kind,
        public array $columns = [],
        public ?string $lockColumn = null,
        public array $lockedValues = [],
        public array $fixed = [],
        public array $increasing = [],
    ) {
        $needsColumns = in_array($kind, [Kind::SetOnce, Kind::MutableColumns], true);
        if ($needsColumns && $columns === []) {
            throw new InvalidArgumentException("{$table}: a {$kind->value} rule must name its columns.");
        }
        if (! $needsColumns && $columns !== []) {
            throw new InvalidArgumentException("{$table}: a {$kind->value} rule takes no columns.");
        }
        if ($fixed !== [] && ! in_array($kind, [Kind::Mutable, Kind::NeverDeleted], true)) {
            throw new InvalidArgumentException("{$table}: only a mutable or never-deleted table names fixed columns.");
        }
        if (array_diff($increasing, $kind === Kind::MutableColumns ? $columns : []) !== []) {
            throw new InvalidArgumentException("{$table}: only a column-restricted table's own columns can be increasing.");
        }
    }

    public static function appendOnly(string $table): self
    {
        return new self($table, Kind::AppendOnly);
    }

    /**
     * @param  list<string>  $columns
     */
    public static function setOnce(string $table, array $columns): self
    {
        return new self($table, Kind::SetOnce, $columns);
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $increasing  those of the columns that may only grow
     */
    public static function mutableColumns(string $table, array $columns, array $increasing = []): self
    {
        return new self($table, Kind::MutableColumns, $columns, increasing: $increasing);
    }

    /**
     * The lock condition is optional here so a table can be classified before it is designed;
     * applying the matrix to an existing table without one fails loudly.
     *
     * @param  list<string>  $lockedValues
     */
    public static function mutableWhileOpen(string $table, ?string $lockColumn = null, array $lockedValues = []): self
    {
        return new self($table, Kind::MutableWhileOpen, lockColumn: $lockColumn, lockedValues: $lockedValues);
    }

    /**
     * @param  list<string>  $fixed
     */
    public static function neverDeleted(string $table, array $fixed = []): self
    {
        return new self($table, Kind::NeverDeleted, fixed: $fixed);
    }

    /**
     * @param  list<string>  $fixed
     */
    public static function mutable(string $table, array $fixed = []): self
    {
        return new self($table, Kind::Mutable, fixed: $fixed);
    }

    public static function framework(string $table): self
    {
        return new self($table, Kind::Framework);
    }

    public static function readOnly(string $table): self
    {
        return new self($table, Kind::ReadOnly);
    }

    /**
     * The table privileges the app role holds. TRUNCATE, REFERENCES and TRIGGER are never granted.
     *
     * @return list<string>
     */
    public function tablePrivileges(): array
    {
        return match ($this->kind) {
            Kind::AppendOnly, Kind::SetOnce, Kind::MutableColumns => ['INSERT', 'SELECT'],
            Kind::MutableWhileOpen, Kind::NeverDeleted => ['INSERT', 'SELECT', 'UPDATE'],
            Kind::Mutable, Kind::Framework => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
            Kind::ReadOnly => ['SELECT'],
        };
    }

    /**
     * Columns the app role may update when it holds no table-wide UPDATE.
     *
     * @return list<string>
     */
    public function updatableColumns(): array
    {
        return $this->columns;
    }
}
