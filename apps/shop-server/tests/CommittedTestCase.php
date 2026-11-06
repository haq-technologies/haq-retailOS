<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * The base for the multi-process suite: the app boots, but nothing wraps a test in a transaction.
 * Every write commits, as it would in a shop (spec section 14) [F6].
 */
abstract class CommittedTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }
}
