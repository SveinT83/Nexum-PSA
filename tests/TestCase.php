<?php

namespace Tests;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** @var class-string */
    protected $seeder = PermissionSeeder::class;

    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        // RefreshDatabase runs immediately after application creation. Refuse the suite before
        // any migration or truncation can touch a cached development or production connection.
        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(sprintf(
                'Refusing to run tests on database connection [%s] / [%s]. Use isolated SQLite :memory: configuration.',
                $connection,
                $database,
            ));
        }

        return $app;
    }

}
