<?php

namespace Nikolaynesov\LaravelSerene\Tests;

use Nikolaynesov\LaravelSerene\LaravelSereneProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelSereneProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Serene' => \Nikolaynesov\LaravelSerene\Facades\Serene::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Use array cache for testing
        config()->set('cache.default', 'array');

        // Set test config
        config()->set('serene.cooldown', 60);
        config()->set('serene.provider', \Nikolaynesov\LaravelSerene\Providers\LogReporter::class);
        config()->set('serene.debug', false);
    }
}
