<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Hard guard against running the suite against a real database.
     *
     * A cached production config (`php artisan config:cache`) silently overrides
     * phpunit.xml's <env> block, which once pointed the suite at the live
     * `nfc_cards` database. RefreshDatabase would have dropped every table.
     * Fail loudly instead of trusting operator discipline.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.default');
        $database   = config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || ! in_array($database, [':memory:', ''], true)) {
            throw new RuntimeException(
                "REFUSING TO RUN TESTS against connection [{$connection}] database [{$database}].\n".
                "Tests must use in-memory SQLite. This almost always means a cached config is\n".
                "overriding phpunit.xml. Fix with:\n\n".
                "    php8.4 artisan config:clear\n"
            );
        }
    }
}
