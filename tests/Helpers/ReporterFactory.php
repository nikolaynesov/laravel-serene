<?php

namespace Nikolaynesov\LaravelSerene\Tests\Helpers;

use Nikolaynesov\LaravelSerene\Contracts\ErrorReporter;
use Nikolaynesov\LaravelSerene\Services\ErrorKeyResolver;
use Nikolaynesov\LaravelSerene\Services\ErrorStatistics;
use Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter;
use Nikolaynesov\LaravelSerene\Services\ThrottleFlusher;
use Nikolaynesov\LaravelSerene\Services\ThrottleManager;
use Nikolaynesov\LaravelSerene\Services\UserTracker;

class ReporterFactory
{
    /**
     * Create a RateLimitedErrorReporter for testing
     */
    public static function create(
        ErrorReporter $provider,
        int $cooldownMinutes = 30,
        bool $debug = false,
        int $maxTrackedUsers = 1000,
        int $maxTrackedErrors = 1000
    ): RateLimitedErrorReporter {
        $keyResolver = new ErrorKeyResolver();
        $throttleManager = new ThrottleManager($cooldownMinutes, $maxTrackedErrors);
        $userTracker = new UserTracker($cooldownMinutes, $maxTrackedUsers);
        $statistics = new ErrorStatistics($cooldownMinutes);
        $flusher = new ThrottleFlusher(
            $provider,
            $keyResolver,
            $throttleManager,
            $userTracker,
            $statistics
        );

        return new RateLimitedErrorReporter(
            $provider,
            $keyResolver,
            $throttleManager,
            $userTracker,
            $statistics,
            $flusher
        );
    }
}
