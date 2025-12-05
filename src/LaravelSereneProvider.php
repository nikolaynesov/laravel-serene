<?php

namespace Nikolaynesov\LaravelSerene;

use Illuminate\Support\ServiceProvider;
use Nikolaynesov\LaravelSerene\Contracts\ErrorReporter;
use Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter;

class LaravelSereneProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/serene.php', 'serene');

        $this->app->bind(ErrorReporter::class, function ($app) {
            $provider = config('serene.provider');

            if (!class_exists($provider)) {
                throw new \InvalidArgumentException("Error reporter provider class [{$provider}] does not exist.");
            }

            if (!is_subclass_of($provider, ErrorReporter::class)) {
                throw new \InvalidArgumentException("Error reporter provider [{$provider}] must implement ErrorReporter interface.");
            }

            return $app->make($provider);
        });

        $this->app->singleton(RateLimitedErrorReporter::class, function ($app) {
            $cooldown = config('serene.cooldown');
            $debug = config('serene.debug');
            $maxTrackedUsers = config('serene.max_tracked_users');

            if (!is_int($cooldown) || $cooldown < 1) {
                throw new \InvalidArgumentException("Cooldown must be a positive integer, got: {$cooldown}");
            }

            if (!is_bool($debug)) {
                throw new \InvalidArgumentException("Debug must be a boolean, got: " . gettype($debug));
            }

            if (!is_int($maxTrackedUsers) || $maxTrackedUsers < 1) {
                throw new \InvalidArgumentException("Max tracked users must be a positive integer, got: " . gettype($maxTrackedUsers));
            }

            return new RateLimitedErrorReporter(
                $app->make(ErrorReporter::class),
                $cooldown,
                $debug,
                $maxTrackedUsers
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/serene.php' => config_path('serene.php'),
        ], 'serene-config');
    }
}