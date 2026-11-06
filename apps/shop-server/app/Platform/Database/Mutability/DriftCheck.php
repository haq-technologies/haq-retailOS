<?php

declare(strict_types=1);

namespace App\Platform\Database\Mutability;

use Illuminate\Database\Connection;

/**
 * Compares the live grants, triggers and role attributes with what the mutability matrix expects
 * (the nightly grant check, spec section 4) [F3]. Any role may run it: it reads only pg_catalog and
 * the has_*_privilege functions. An empty result means no drift.
 */
final readonly class DriftCheck
{
    private const TABLE_PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'];

    public function __construct(
        private Matrix $matrix,
        private Roles $roles,
        private Applier $applier,
    ) {}

    /**
     * @return list<string> One plain sentence per difference.
     */
    public function problems(Connection $db): array
    {
        $problems = [...$this->roleProblems($db)];

        if ($this->bool($db, "SELECT has_schema_privilege(?, 'public', 'CREATE') AS v", [$this->roles->app])) {
            $problems[] = 'The app role can create objects in the public schema.';
        }

        foreach (Schema::tables($db) as $table) {
            $rule = $this->matrix->rule($table);
            if ($rule === null) {
                $problems[] = "{$table} is not in the mutability matrix.";

                continue;
            }
            array_push($problems, ...$this->privilegeProblems($db, $rule));
            array_push($problems, ...$this->triggerProblems($db, $rule));
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function roleProblems(Connection $db): array
    {
        $problems = [];
        foreach ([$this->roles->app, $this->roles->reporting] as $role) {
            /** @var object{rolsuper: bool, rolcreaterole: bool, rolcreatedb: bool, rolbypassrls: bool, rolreplication: bool}|null $row */
            $row = $db->selectOne(
                'SELECT rolsuper, rolcreaterole, rolcreatedb, rolbypassrls, rolreplication FROM pg_roles WHERE rolname = ?',
                [$role]
            );
            if ($row === null) {
                $problems[] = "The role {$role} does not exist.";

                continue;
            }
            foreach (['rolsuper' => 'is a superuser', 'rolcreaterole' => 'can create roles', 'rolcreatedb' => 'can create databases',
                'rolbypassrls' => 'bypasses row-level security', 'rolreplication' => 'can replicate'] as $attribute => $what) {
                if ($row->{$attribute}) {
                    $problems[] = "The role {$role} {$what}.";
                }
            }
            $owned = $this->bool($db, <<<'SQL'
                SELECT EXISTS (
                    SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                    WHERE n.nspname = 'public' AND pg_get_userbyid(c.relowner) = ?
                ) AS v
                SQL, [$role]);
            if ($owned) {
                $problems[] = "The role {$role} owns objects in the public schema.";
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function privilegeProblems(Connection $db, Rule $rule): array
    {
        $problems = [];
        $expected = $rule->tablePrivileges();

        foreach (self::TABLE_PRIVILEGES as $privilege) {
            $has = $this->bool($db, 'SELECT has_table_privilege(?, ?, ?) AS v', [$this->roles->app, "public.\"{$rule->table}\"", $privilege]);
            $should = in_array($privilege, $expected, true);
            if ($has !== $should) {
                $problems[] = $has
                    ? "The app role holds {$privilege} on {$rule->table}, which the matrix does not allow."
                    : "The app role lacks {$privilege} on {$rule->table}.";
            }

            if ($this->bool($db, 'SELECT has_table_privilege(?, ?, ?) AS v', [$this->roles->reporting, "public.\"{$rule->table}\"", $privilege])) {
                $problems[] = "The reporting role holds {$privilege} on {$rule->table}; it may read only cost-safe views.";
            }
        }

        // Column rights matter only where the table-wide UPDATE is not held: a widened table-wide UPDATE
        // is reported above and covers every column.
        $tableUpdate = $this->bool($db, 'SELECT has_table_privilege(?, ?, ?) AS v', [$this->roles->app, "public.\"{$rule->table}\"", 'UPDATE']);
        if (! in_array('UPDATE', $expected, true) && ! $tableUpdate) {
            /** @var list<object{name: string}> $columns */
            $columns = $db->select(<<<'SQL'
                SELECT a.attname AS name FROM pg_attribute a
                WHERE a.attrelid = ?::regclass AND a.attnum > 0 AND NOT a.attisdropped
                SQL, ["public.\"{$rule->table}\""]);
            foreach ($columns as $column) {
                $has = $this->bool($db, 'SELECT has_column_privilege(?, ?, ?, ?) AS v', [$this->roles->app, "public.\"{$rule->table}\"", $column->name, 'UPDATE']);
                $should = in_array($column->name, $rule->updatableColumns(), true);
                if ($has !== $should) {
                    $problems[] = $has
                        ? "The app role can update {$rule->table}.{$column->name}, which the matrix does not allow."
                        : "The app role cannot update {$rule->table}.{$column->name}.";
                }
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function triggerProblems(Connection $db, Rule $rule): array
    {
        $problems = [];
        $expected = array_keys($this->applier->triggers($rule));

        /** @var list<object{name: string, enabled: string}> $rows */
        $rows = $db->select(<<<'SQL'
            SELECT tg.tgname AS name, tg.tgenabled AS enabled
            FROM pg_trigger tg JOIN pg_class t ON t.oid = tg.tgrelid JOIN pg_namespace n ON n.oid = t.relnamespace
            WHERE NOT tg.tgisinternal AND n.nspname = 'public' AND t.relname = ? AND starts_with(tg.tgname, ?)
            SQL, [$rule->table, Applier::TRIGGER_PREFIX]);
        $live = [];
        foreach ($rows as $row) {
            $live[$row->name] = $row->enabled;
        }

        foreach ($expected as $name) {
            if (! isset($live[$name])) {
                $problems[] = "{$rule->table} is missing its {$name} trigger.";
            } elseif ($live[$name] === 'D') {
                $problems[] = "The {$name} trigger on {$rule->table} is disabled.";
            }
        }
        foreach (array_diff(array_keys($live), $expected) as $name) {
            $problems[] = "{$rule->table} has an unexpected {$name} trigger.";
        }

        return $problems;
    }

    /**
     * @param  list<string>  $bindings
     */
    private function bool(Connection $db, string $sql, array $bindings): bool
    {
        /** @var object{v: bool}|null $row */
        $row = $db->selectOne($sql, $bindings);

        return $row !== null && $row->v;
    }
}
