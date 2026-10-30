<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if ($app->configurationIsCached() || ! $app->environment('testing')) {
            throw new RuntimeException('Tests require uncached testing configuration; refusing to use application data.');
        }
        $connection = $app['config']->get('database.default');
        $driver = $app['config']->get('database.connections.'.$connection.'.driver');
        $database = $app['config']->get('database.connections.'.$connection.'.database');
        $isolated = ($driver === 'sqlite' && $database === ':memory:')
            || ($driver === 'pgsql' && is_string($database) && preg_match('/(?:^|_)test(?:$|_)/', $database));
        if (! $isolated) {
            throw new RuntimeException('Refusing database refresh outside in-memory SQLite or an explicitly named PostgreSQL test database.');
        }

        return $app;
    }
}
