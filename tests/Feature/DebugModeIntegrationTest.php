<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Nikolaynesov\LaravelSerene\Facades\Serene;

beforeEach(function () {
    Carbon::setTestNow('2025-12-05 10:00:00');
    Cache::flush();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('facade with debug mode enabled logs reports', function () {
    config(['serene.debug' => true]);

    // Need to re-bind to pick up new config
    app()->forgetInstance(\Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter::class);

    $exception = new RuntimeException('Debug test');

    Log::shouldReceive('error')->once();
    Log::shouldReceive('info')->once()->with(
        \Mockery::pattern('/^\[Serene\].*reported$/'),
        \Mockery::type('array')
    );

    Serene::report($exception);
});

test('facade with debug mode enabled logs throttles', function () {
    config(['serene.debug' => true]);

    app()->forgetInstance(\Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter::class);

    $exception = new RuntimeException('Debug throttle test');

    Log::shouldReceive('error')->once();
    Log::shouldReceive('info')->once();

    Serene::report($exception); // Reported

    Log::shouldReceive('debug')->once()->with(
        \Mockery::pattern('/^\[Serene\].*throttled$/'),
        \Mockery::type('array')
    );

    Serene::report($exception); // Throttled
});

test('facade with debug mode disabled does not log', function () {
    config(['serene.debug' => false]);

    app()->forgetInstance(\Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter::class);

    $exception = new RuntimeException('No debug test');

    Log::shouldReceive('error')->once(); // Only from LogReporter
    Log::shouldNotReceive('info');
    Log::shouldNotReceive('debug');

    Serene::report($exception);
    Serene::report($exception); // Throttled, still no debug log
});

test('debug mode can be enabled via environment config', function () {
    config(['serene.debug' => env('SERENE_DEBUG', false)]);

    // Simulate environment variable
    putenv('SERENE_DEBUG=true');
    config(['serene.debug' => true]);

    app()->forgetInstance(\Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter::class);

    $exception = new RuntimeException('Env test');

    Log::shouldReceive('error')->once();
    Log::shouldReceive('info')->once();

    Serene::report($exception);

    putenv('SERENE_DEBUG'); // Clean up
});
