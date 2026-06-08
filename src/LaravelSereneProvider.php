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

        // Bind individual services
        $this->app->singleton(Services\ErrorKeyResolver::class, function () {
            return new Services\ErrorKeyResolver();
        });

        $this->app->singleton(Services\ThrottleManager::class, function () {
            $cooldown = config('serene.cooldown') ?? 60;
            $maxTrackedErrors = config('serene.max_tracked_errors') ?? 1000;

            $this->validateConfig($cooldown, 'cooldown');
            $this->validateConfig($maxTrackedErrors, 'max_tracked_errors');

            return new Services\ThrottleManager($cooldown, $maxTrackedErrors);
        });

        $this->app->singleton(Services\UserTracker::class, function () {
            $cooldown = config('serene.cooldown') ?? 60;
            $maxTrackedUsers = config('serene.max_tracked_users') ?? 1000;

            $this->validateConfig($cooldown, 'cooldown');
            $this->validateConfig($maxTrackedUsers, 'max_tracked_users');

            return new Services\UserTracker($cooldown, $maxTrackedUsers);
        });

        $this->app->singleton(Services\ErrorStatistics::class, function () {
            $cooldown = config('serene.cooldown') ?? 60;

            $this->validateConfig($cooldown, 'cooldown');

            return new Services\ErrorStatistics($cooldown);
        });

        $this->app->singleton(Services\ThrottleFlusher::class, function ($app) {
            return new Services\ThrottleFlusher(
                $app->make(ErrorReporter::class),
                $app->make(Services\ErrorKeyResolver::class),
                $app->make(Services\ThrottleManager::class),
                $app->make(Services\UserTracker::class),
                $app->make(Services\ErrorStatistics::class)
            );
        });

        $this->app->singleton(RateLimitedErrorReporter::class, function ($app) {
            // Validate debug config
            $debug = config('serene.debug') ?? false;
            if (!is_bool($debug)) {
                throw new \InvalidArgumentException('Debug must be a boolean');
            }

            return new RateLimitedErrorReporter(
                $app->make(ErrorReporter::class),
                $app->make(Services\ErrorKeyResolver::class),
                $app->make(Services\ThrottleManager::class),
                $app->make(Services\UserTracker::class),
                $app->make(Services\ErrorStatistics::class),
                $app->make(Services\ThrottleFlusher::class)
            );
        });
    }

    /**
     * Validate configuration value is a positive integer
     */
    protected function validateConfig(mixed $value, string $name): void
    {
        if (!is_int($value)) {
            throw new \InvalidArgumentException(ucfirst(str_replace('_', ' ', $name)) . " must be a positive integer, got: " . var_export($value, true));
        }

        if ($value < 1) {
            throw new \InvalidArgumentException(ucfirst(str_replace('_', ' ', $name)) . " must be a positive integer, got: " . var_export($value, true));
        }

        // Additional validation for cooldown to ensure reasonable bounds
        if ($name === 'cooldown') {
            if ($value > 10080) { // Max 1 week (7 days * 24 hours * 60 minutes)
                throw new \InvalidArgumentException(
                    "Cooldown must not exceed 10080 minutes (1 week), got: {$value}"
                );
            }
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/serene.php' => config_path('serene.php'),
        ], 'serene-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Nikolaynesov\LaravelSerene\Console\FlushThrottledErrorsCommand::class,
            ]);

            // Automatically schedule flush to catch orphaned throttled errors
            $this->app->booted(function () {
                $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
                $cooldown = config('serene.cooldown');

                // Calculate flush interval: cooldown / 6 (provides 6 safety checks per cycle)
                // Minimum 1 minute to handle very short cooldowns
                $flushInterval = max(1, (int) floor($cooldown / 6));

                // Use resolver to apply appropriate schedule frequency
                $event = $schedule->command('serene:flush-throttled');
                $resolver = new \Nikolaynesov\LaravelSerene\Support\FlushScheduleResolver();
                $resolver->apply($event, $flushInterval);
            });
        }
    }
}