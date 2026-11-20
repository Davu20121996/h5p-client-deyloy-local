<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        // This runs before RefreshDatabase can execute migrate:fresh.
        if ($app->configurationIsCached() || ! $app->environment('testing') ||
            $app['config']->get('database.default') !== 'sqlite' ||
            $app['config']->get('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Tests require uncached testing configuration and an in-memory SQLite database.');
        }

        return $app;
    }
}
