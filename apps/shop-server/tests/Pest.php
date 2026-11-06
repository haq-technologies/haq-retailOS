<?php

declare(strict_types=1);

use Tests\CommittedTestCase;
use Tests\TestCase;

/*
 * Feature tests run against a real PostgreSQL (the retail_test database; see phpunit.xml), each inside a
 * rolled-back transaction. That never counts for concurrency, crash or restore tests (spec section 14):
 * those live in tests/Concurrency, run as separate processes with committed transactions on their own
 * database (phpunit.concurrency.xml).
 */
pest()->extend(TestCase::class)->in('Feature');
pest()->extend(CommittedTestCase::class)->in('Concurrency');
