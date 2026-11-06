<?php

declare(strict_types=1);

namespace Tests;

use App\Audit\Spool;
use App\Organisation\BranchKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabase;

/**
 * Every feature test runs against the migrated retail_test database. RefreshDatabase lives here, not in
 * the test files, so the owner-role migration below takes precedence over the trait's own methods.
 */
abstract class TestCase extends BaseTestCase
{
    protected string $spoolPath = '';

    use RefreshDatabase {
        migrateFreshUsing as refreshDatabaseMigrateFreshUsing;
        migrateDatabases as refreshDatabaseMigrateDatabases;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // PHP tests never need the built frontend; the frontend has its own tests (Vitest).
        $this->withoutVite();

        // A browser's same-origin fetch sends cookies (such as the counter's device cookie) with JSON requests.
        $this->withCredentials();

        // Each test spools failures to its own file, never the development server's spool.
        $this->spoolPath = storage_path('framework/testing/spool-'.getmypid().'-'.bin2hex(random_bytes(4)).'.jsonl');
        $this->app->instance(Spool::class, new Spool($this->spoolPath));
        $this->beforeApplicationDestroyed(fn () => @unlink($this->spoolPath));

        // And its own branch key.
        $keyDir = storage_path('framework/testing/keys-'.getmypid().'-'.bin2hex(random_bytes(4)));
        $this->app->instance(BranchKey::class, new BranchKey($keyDir));
        config(['organisation.key_dir' => $keyDir]);
        $this->beforeApplicationDestroyed(function () use ($keyDir): void {
            @unlink($keyDir.'/branch.key');
            @rmdir($keyDir);
        });
    }

    /**
     * Tests rebuild the schema as the owner role and then run as the app role, exactly as a shop does
     * (spec section 4). The matrix is applied after every migration, so tests see the real grants.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return [...$this->refreshDatabaseMigrateFreshUsing(), '--database' => 'pgsql_owner'];
    }

    protected function migrateDatabases(): void
    {
        TestDatabase::assertSafe();
        $this->refreshDatabaseMigrateDatabases();
        $this->artisan('db:mutability', ['--database' => 'pgsql_owner'])->assertSuccessful();
    }
}
