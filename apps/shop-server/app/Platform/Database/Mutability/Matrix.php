<?php

declare(strict_types=1);

namespace App\Platform\Database\Mutability;

use InvalidArgumentException;

/**
 * The mutability matrix: every table and how it may change (spec section 5) [F3, S15].
 * Grants, triggers and the nightly grant check are all generated from it, so the rules live in one
 * place. A table that exists in the database but is missing here stops the migration.
 */
final readonly class Matrix
{
    /** @var array<string, Rule> */
    private array $rules;

    /**
     * @param  list<Rule>  $rules
     */
    public function __construct(array $rules)
    {
        $byTable = [];
        foreach ($rules as $rule) {
            if (isset($byTable[$rule->table])) {
                throw new InvalidArgumentException("{$rule->table} appears twice in the mutability matrix.");
            }
            $byTable[$rule->table] = $rule;
        }
        ksort($byTable);
        $this->rules = $byTable;
    }

    /**
     * The shop server's matrix. Tables are listed before they are built, so each new table only
     * needs its migration; the rule is already here.
     */
    public static function shopServer(): self
    {
        $appendOnly = [
            // Sales and their records
            'sales', 'sale_lines', 'payments', 'payment_allocations', 'returns', 'voucher_entries',
            // Cost and prices: the latest row wins
            'cost_entries', 'cost_resolutions', 'item_prices',
            // Shifts, cash and counter events
            'shift_events', 'cash_events', 'paid_outs', 'parked_sale_events', 'sale_timings',
            // Catalogue history: an undo is a new row
            'merge_events',
            // Histories: each change adds a row
            'credit_limit_history', 'settings_history',
            // Audit and numbering
            'audit_log', 'audit_sensitive', 'audit_roots', 'epochs',
            // Stock, once stock tracking is on (Pro)
            'stock_movements',
        ];

        $mutable = [
            // Catalogue: every change is audited
            'categories', 'category_templates', 'items', 'item_facets', 'picklist_values', 'brands',
            'barcodes', 'barcode_ignore', 'aliases',
            // Organisation and configuration
            'tenants', 'branches', 'terminals', 'drawers', 'customers', 'settings', 'licenses',
            'sync_conflicts',
        ];

        return new self([
            ...array_map(Rule::appendOnly(...), $appendOnly),
            ...array_map(Rule::mutable(...), $mutable),

            // Only bound_at may be set, once, from NULL [S3]
            Rule::setOnce('approvals', ['bound_at']),

            // A closed shift never changes [F3]
            Rule::mutableWhileOpen('shifts', 'status', ['closed', 'auto_closed']),
            // The lock condition is set when parked sales are designed in Phase 3
            Rule::mutableWhileOpen('parked_sales'),

            Rule::mutableColumns('receipt_counters', ['last_no']),
            Rule::mutableColumns('print_jobs', ['status', 'attempts']),

            // Users are deactivated, never deleted [F18]
            Rule::neverDeleted('users'),

            // Laravel's own tables (D21)
            ...array_map(Rule::framework(...), ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs']),
            Rule::readOnly('migrations'),
        ]);
    }

    /**
     * A copy with more rules, for tests that classify probe tables.
     *
     * @param  list<Rule>  $rules
     */
    public function with(array $rules): self
    {
        return new self([...array_values($this->rules), ...$rules]);
    }

    public function rule(string $table): ?Rule
    {
        return $this->rules[$table] ?? null;
    }

    /**
     * @return array<string, Rule>
     */
    public function rules(): array
    {
        return $this->rules;
    }
}
