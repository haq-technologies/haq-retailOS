<?php

declare(strict_types=1);

namespace App\Platform\Database\Mutability;

use Illuminate\Database\Connection;
use LogicException;

/**
 * Applies the mutability matrix to the database: the grants that decide what the app role may attempt,
 * and the triggers that hold the rules even if a grant drifts (spec section 4) [F3, S15].
 * It runs as the owner role, after every migration, and is safe to run again.
 */
final readonly class Applier
{
    /** Every trigger this class manages starts with this prefix, so it can replace them cleanly. */
    public const TRIGGER_PREFIX = 'haq_';

    public function __construct(
        private Matrix $matrix,
        private Roles $roles,
    ) {}

    public function apply(Connection $db): void
    {
        $tables = Schema::tables($db);
        $unclassified = array_values(array_filter($tables, fn (string $t) => $this->matrix->rule($t) === null));
        if ($unclassified !== []) {
            throw UnclassifiedTable::tables($unclassified);
        }

        $db->transaction(function () use ($db, $tables): void {
            foreach (self::functions() as $sql) {
                $db->statement($sql);
            }

            $app = $this->quote($this->roles->app);
            $reporting = $this->quote($this->roles->reporting);

            // Nobody but the owner creates objects; the app and reporting roles may only use the schema.
            $db->statement('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
            $db->statement("GRANT USAGE ON SCHEMA public TO {$app}, {$reporting}");

            foreach ($tables as $table) {
                $rule = $this->matrix->rule($table) ?? throw new LogicException("No rule for {$table}");
                $this->applyRule($db, $rule, $app, $reporting);
            }
        });
    }

    private function applyRule(Connection $db, Rule $rule, string $app, string $reporting): void
    {
        $t = $this->quote($rule->table);

        $db->statement("REVOKE ALL ON TABLE {$t} FROM PUBLIC, {$app}, {$reporting}");
        $db->statement('GRANT '.implode(', ', $rule->tablePrivileges())." ON TABLE {$t} TO {$app}");
        if ($rule->updatableColumns() !== []) {
            $columns = implode(', ', array_map($this->quote(...), $rule->updatableColumns()));
            $db->statement("GRANT UPDATE ({$columns}) ON TABLE {$t} TO {$app}");
        }

        // Integer keys exist only on Laravel's own tables (D21); their sequences need USAGE.
        foreach (Schema::sequencesOf($db, $rule->table) as $sequence) {
            $s = $this->quote($sequence);
            $db->statement("REVOKE ALL ON SEQUENCE {$s} FROM PUBLIC, {$app}, {$reporting}");
            if (in_array('INSERT', $rule->tablePrivileges(), true)) {
                $db->statement("GRANT USAGE, SELECT ON SEQUENCE {$s} TO {$app}");
            }
        }

        foreach (Schema::triggersOf($db, $rule->table, self::TRIGGER_PREFIX) as $trigger) {
            $db->statement('DROP TRIGGER '.$this->quote($trigger)." ON {$t}");
        }
        foreach ($this->triggers($rule) as $sql) {
            $db->statement($sql);
        }
    }

    /**
     * The triggers a rule needs, by name. The drift check expects exactly these.
     *
     * @return array<string, string>
     */
    public function triggers(Rule $rule): array
    {
        $t = $this->quote($rule->table);
        $p = self::TRIGGER_PREFIX;
        $reject = fn (string $name, string $events, string $why) => "CREATE TRIGGER {$p}{$name} BEFORE {$events} ON {$t} "
            ."FOR EACH STATEMENT EXECUTE FUNCTION haq_reject_change('{$why}')";
        $columns = implode(', ', array_map(fn (string $c) => $this->literal($c), $rule->columns));

        $triggers = match ($rule->kind) {
            Kind::AppendOnly => [
                'append_only' => $reject('append_only', 'UPDATE OR DELETE OR TRUNCATE', 'the table is append-only'),
            ],
            Kind::SetOnce => [
                'no_delete' => $reject('no_delete', 'DELETE OR TRUNCATE', 'rows are never deleted'),
                'set_once' => "CREATE TRIGGER {$p}set_once BEFORE UPDATE ON {$t} FOR EACH ROW "
                    ."EXECUTE FUNCTION haq_guard_columns('once', {$columns})",
            ],
            Kind::MutableColumns => [
                'no_delete' => $reject('no_delete', 'DELETE OR TRUNCATE', 'rows are never deleted'),
                'columns' => "CREATE TRIGGER {$p}columns BEFORE UPDATE ON {$t} FOR EACH ROW "
                    ."EXECUTE FUNCTION haq_guard_columns('free', {$columns})",
            ],
            Kind::MutableWhileOpen => [
                'no_delete' => $reject('no_delete', 'DELETE OR TRUNCATE', 'rows are never deleted'),
                'closed' => "CREATE TRIGGER {$p}closed BEFORE UPDATE ON {$t} FOR EACH ROW "
                    .'EXECUTE FUNCTION haq_reject_when_closed('.$this->lockArguments($rule).')',
            ],
            Kind::NeverDeleted => [
                'no_delete' => $reject('no_delete', 'DELETE OR TRUNCATE', 'rows are never deleted'),
            ],
            Kind::Mutable, Kind::Framework => [
                'no_truncate' => $reject('no_truncate', 'TRUNCATE', 'tables are never truncated'),
            ],
            // The owner writes the migrations table during migrations, so it carries no trigger.
            Kind::ReadOnly => [],
        };

        if ($rule->fixed !== []) {
            $fixed = implode(', ', array_map(fn (string $c) => $this->literal($c), $rule->fixed));
            $triggers['fixed'] = "CREATE TRIGGER {$p}fixed BEFORE UPDATE ON {$t} FOR EACH ROW "
                ."EXECUTE FUNCTION haq_guard_fixed({$fixed})";
        }

        $named = [];
        foreach ($triggers as $name => $sql) {
            $named[$p.$name] = $sql;
        }

        return $named;
    }

    private function lockArguments(Rule $rule): string
    {
        if ($rule->lockColumn === null || $rule->lockedValues === []) {
            throw new LogicException(
                "{$rule->table} exists, but its mutable-while-open rule names no lock column and closed values. "
                .'Set them in the mutability matrix.'
            );
        }

        return implode(', ', array_map($this->literal(...), [$rule->lockColumn, ...$rule->lockedValues]));
    }

    /**
     * The trigger functions. Each raises SQLSTATE 42501 (insufficient privilege) with a plain message.
     *
     * @return list<string>
     */
    public static function functions(): array
    {
        return [
            <<<'SQL'
            CREATE OR REPLACE FUNCTION haq_reject_change() RETURNS trigger LANGUAGE plpgsql AS $fn$
            BEGIN
                RAISE EXCEPTION 'HAQ Retail OS: % on % is not allowed: %', TG_OP, TG_TABLE_NAME, TG_ARGV[0]
                    USING ERRCODE = '42501';
            END
            $fn$
            SQL,
            <<<'SQL'
            CREATE OR REPLACE FUNCTION haq_guard_columns() RETURNS trigger LANGUAGE plpgsql AS $fn$
            DECLARE
                mode text := TG_ARGV[0];
                allowed text[] := TG_ARGV[1:TG_NARGS - 1];
                old_row jsonb := to_jsonb(OLD);
                new_row jsonb := to_jsonb(NEW);
                col text;
            BEGIN
                FOR col IN SELECT jsonb_object_keys(new_row) LOOP
                    IF old_row -> col IS DISTINCT FROM new_row -> col THEN
                        IF NOT (col = ANY (allowed)) THEN
                            RAISE EXCEPTION 'HAQ Retail OS: %.% cannot change', TG_TABLE_NAME, col
                                USING ERRCODE = '42501';
                        END IF;
                        IF mode = 'once' AND jsonb_typeof(old_row -> col) <> 'null' THEN
                            RAISE EXCEPTION 'HAQ Retail OS: %.% is set once, and it is already set', TG_TABLE_NAME, col
                                USING ERRCODE = '42501';
                        END IF;
                    END IF;
                END LOOP;
                RETURN NEW;
            END
            $fn$
            SQL,
            <<<'SQL'
            CREATE OR REPLACE FUNCTION haq_guard_fixed() RETURNS trigger LANGUAGE plpgsql AS $fn$
            DECLARE
                col text;
            BEGIN
                FOREACH col IN ARRAY TG_ARGV LOOP
                    IF to_jsonb(OLD) -> col IS DISTINCT FROM to_jsonb(NEW) -> col THEN
                        RAISE EXCEPTION 'HAQ Retail OS: %.% never changes once written', TG_TABLE_NAME, col
                            USING ERRCODE = '42501';
                    END IF;
                END LOOP;
                RETURN NEW;
            END
            $fn$
            SQL,
            <<<'SQL'
            CREATE OR REPLACE FUNCTION haq_reject_when_closed() RETURNS trigger LANGUAGE plpgsql AS $fn$
            BEGIN
                IF (to_jsonb(OLD) ->> TG_ARGV[0]) = ANY (TG_ARGV[1:TG_NARGS - 1]) THEN
                    RAISE EXCEPTION 'HAQ Retail OS: this % row is closed and cannot change', TG_TABLE_NAME
                        USING ERRCODE = '42501';
                END IF;
                RETURN NEW;
            END
            $fn$
            SQL,
        ];
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
