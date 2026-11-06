<?php

declare(strict_types=1);

use Tests\TestCase;

/*
 * Feature tests run against a real PostgreSQL (the retail_test database; see phpunit.xml).
 * RefreshDatabase and DatabaseTransactions are fine for ordinary tests, but they never count for
 * concurrency, crash or restore tests: those run as multi-process tests (spec section 14).
 */
pest()->extend(TestCase::class)->in('Feature');
