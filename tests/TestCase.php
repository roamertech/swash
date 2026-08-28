<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    /**
     * The only database this suite is ever allowed to touch.
     */
    private const ALLOWED_CONNECTION = 'pgsql';

    private const ALLOWED_DATABASE = 'swash_test';

    /**
     * Refuse to run against anything but the dedicated test database.
     *
     * RefreshDatabase runs migrate:fresh, which drops every table it finds.
     * phpunit.xml forces the connection, but a forced value is still only a
     * default that someone can edit away, and the failure mode is silent:
     * the suite would go green while emptying production.
     *
     * setUpTraits() is the last hook that runs before RefreshDatabase boots,
     * so this is the final point where stopping is still free. It exits the
     * process rather than failing an assertion, because a failed assertion
     * would let the remaining tests run and drop the tables anyway.
     */
    protected function setUpTraits(): array
    {
        $connection = (string) config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== self::ALLOWED_CONNECTION || $database !== self::ALLOWED_DATABASE) {
            $this->refuseToRun($connection, $database);
        }

        $this->requireReachableTestDatabase($connection);

        return parent::setUpTraits();
    }

    /**
     * Fail with the fix rather than with a driver stack trace.
     *
     * The database is pointed at correctly but may simply not exist yet, and
     * the raw PDO error buries the one command that resolves it.
     */
    private function requireReachableTestDatabase(string $connection): void
    {
        try {
            DB::connection($connection)->getPdo();
        } catch (Throwable $e) {
            $rule = str_repeat('=', 72);

            fwrite(STDERR, PHP_EOL.$rule.PHP_EOL);
            fwrite(STDERR, 'CANNOT REACH THE TEST DATABASE'.PHP_EOL.PHP_EOL);
            fwrite(STDERR, '  '.self::ALLOWED_DATABASE.' is not reachable on this host.'.PHP_EOL.PHP_EOL);
            fwrite(STDERR, 'Create it once, as root:'.PHP_EOL);
            fwrite(STDERR, '  sudo -u postgres psql -c "CREATE DATABASE '.self::ALLOWED_DATABASE.' OWNER swash;"'.PHP_EOL.PHP_EOL);
            fwrite(STDERR, 'The swash role has no CREATEDB privilege, so this step needs root.'.PHP_EOL);
            fwrite(STDERR, 'Driver said: '.$e->getMessage().PHP_EOL);
            fwrite(STDERR, $rule.PHP_EOL.PHP_EOL);

            exit(1);
        }
    }

    private function refuseToRun(string $connection, mixed $database): never
    {
        $rule = str_repeat('=', 72);
        $expected = self::ALLOWED_CONNECTION.' / '.self::ALLOWED_DATABASE;
        $actual = $connection.' / '.var_export($database, true);

        fwrite(STDERR, PHP_EOL.$rule.PHP_EOL);
        fwrite(STDERR, 'REFUSING TO RUN THE TEST SUITE'.PHP_EOL.PHP_EOL);
        fwrite(STDERR, "  expected:  {$expected}".PHP_EOL);
        fwrite(STDERR, "  resolved:  {$actual}".PHP_EOL.PHP_EOL);
        fwrite(STDERR, 'These tests call migrate:fresh, which drops every table on the'.PHP_EOL);
        fwrite(STDERR, 'connection they are given. Running them anywhere but swash_test'.PHP_EOL);
        fwrite(STDERR, 'destroys data.'.PHP_EOL.PHP_EOL);
        fwrite(STDERR, 'Two things usually cause this:'.PHP_EOL);
        fwrite(STDERR, '  1. The shell exports DB_* and phpunit.xml lost force="true".'.PHP_EOL);
        fwrite(STDERR, '     Check with: env | grep ^DB_'.PHP_EOL);
        fwrite(STDERR, '  2. swash_test does not exist yet. Create it as root:'.PHP_EOL);
        fwrite(STDERR, '     sudo -u postgres psql -c "CREATE DATABASE swash_test OWNER swash;"'.PHP_EOL);
        fwrite(STDERR, $rule.PHP_EOL.PHP_EOL);

        exit(1);
    }
}
