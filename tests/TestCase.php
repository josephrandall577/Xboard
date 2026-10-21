<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        // Never let RefreshDatabase use a developer or deployment database.
        $app['config']->set([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'cache.stores.redis' => ['driver' => 'array'],
            'queue.default' => 'null',
            'session.driver' => 'array',
        ]);
        $app['cache']->forgetDriver('redis');
        $app->forgetInstance(\App\Support\Setting::class);

        return $app;
    }
}
