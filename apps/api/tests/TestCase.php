<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->guardAgainstNonTestDatabase();

        /*
         * A test starts with an empty cache.
         *
         * `RefreshDatabase` rolls the database back and leaves the array store alone, so a
         * cached lookup outlived the rows it was built from — the catalogue coverage map
         * and the style adjacency both cache for minutes, and a test that seeded a
         * catalogue left its answers behind for the next one. The symptom was a design
         * test that passed alone and failed in the suite, which is the worst shape a
         * failure can take: it looks like flakiness and it is a real leak.
         */
        Cache::flush();
    }

    /**
     * RefreshDatabase truncates whatever database the connection points at, so a
     * misconfigured environment turns a "green" run into local data loss. The api
     * container also carries development DB_* values, which makes the mistake easy
     * to make and invisible once made — fail loudly instead.
     */
    private function guardAgainstNonTestDatabase(): void
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Refusing to run tests against database '{$database}' on connection '{$connection}'. "
                .'Test databases must end with "_test" — check the <env> entries in phpunit.xml.'
            );
        }
    }
}
