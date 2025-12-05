<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter;
use Nikolaynesov\LaravelSerene\Tests\Helpers\FakeErrorReporter;

beforeEach(function () {
    Carbon::setTestNow('2025-12-05 10:00:00');
    Cache::flush();
    $this->fake = new FakeErrorReporter();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('debug mode disabled does not log when error is reported', function () {
    $reporter = new RateLimitedErrorReporter($this->fake, 60, false, 1000);
    $exception = new RuntimeException('Test');

    Log::shouldNotReceive('info');
    Log::shouldNotReceive('debug');

    $reporter->report($exception);
});

test('debug mode disabled does not log when error is throttled', function () {
    $reporter = new RateLimitedErrorReporter($this->fake, 60, false, 1000);
    $exception = new RuntimeException('Test');

    $reporter->report($exception); // Reported

    Log::shouldNotReceive('debug');

    $reporter->report($exception); // Throttled, should not log
});

test('debug mode enabled logs when error is reported', function () {
    $reporter = new RateLimitedErrorReporter($this->fake, 60, true, 1000);
    $exception = new RuntimeException('Test');
    $key = \Nikolaynesov\LaravelSerene\Support\KeyGenerator::fromException($exception);

    Log::shouldReceive('info')
        ->once()
        ->with(
            "[Serene] {$key} reported",
            \Mockery::subset([
                'affected_users' => [],
                'count' => 0,
                'occurrences' => 1,
                'throttled' => 0,
            ])
        );

    $reporter->report($exception);
});

test('debug mode enabled logs when error is throttled', function () {
    $reporter = new RateLimitedErrorReporter($this->fake, 60, true, 1000);
    $exception = new RuntimeException('Test');
    $key = \Nikolaynesov\LaravelSerene\Support\KeyGenerator::fromException($exception);

    Log::shouldReceive('info')->once(); // First report

    $reporter->report($exception); // Reported

    Log::shouldReceive('debug')
        ->once()
        ->with(
            "[Serene] {$key} throttled",
            \Mockery::subset([
                'occurrences' => 2,
                'throttled' => 1,
            ])
        );

    $reporter->report($exception); // Throttled
});

test('debug mode logs include affected users', function () {
    $reporter = new RateLimitedErrorReporter($this->fake, 60, true, 1000);
    $exception = new RuntimeException('Test');
    $key = \Nikolaynesov\LaravelSerene\Support\KeyGenerator::fromException($exception);

    Log::shouldReceive('info')
        ->once()
        ->with(
            "[Serene] {$key} reported",
            [
                'affected_users' => [1],
                'count' => 1,
                'occurrences' => 1,
                'throttled' => 0,
            ]
        );

    $reporter->report($exception, ['user_id' => 1]);
});

test('debug mode logs show throttle count accumulation', function () {
    $reporter = new RateLimitedErrorReporter($this->fake, 60, true, 1000);
    $exception = new RuntimeException('Test');
    $key = \Nikolaynesov\LaravelSerene\Support\KeyGenerator::fromException($exception);

    Log::shouldReceive('info')->once(); // First report

    $reporter->report($exception);

    Log::shouldReceive('debug')
        ->once()
        ->with(
            "[Serene] {$key} throttled",
            [
                'occurrences' => 2,
                'throttled' => 1,
            ]
        );

    $reporter->report($exception);

    Log::shouldReceive('debug')
        ->once()
        ->with(
            "[Serene] {$key} throttled",
            [
                'occurrences' => 3,
                'throttled' => 2,
            ]
        );

    $reporter->report($exception);
});

test('debug mode uses info level for reports', function () {
    $reporter = new RateLimitedErrorReporter($this->fake, 60, true, 1000);
    $exception = new RuntimeException('Test');

    Log::shouldReceive('info')->once();
    Log::shouldNotReceive('warning');
    Log::shouldNotReceive('error');

    $reporter->report($exception);
});

test('debug mode uses debug level for throttles', function () {
    $reporter = new RateLimitedErrorReporter($this->fake, 60, true, 1000);
    $exception = new RuntimeException('Test');

    Log::shouldReceive('info')->once(); // First report

    $reporter->report($exception);

    Log::shouldReceive('debug')->once();
    Log::shouldNotReceive('warning');
    Log::shouldNotReceive('error');

    $reporter->report($exception); // Throttled
});

test('debug logs use Serene prefix', function () {
    $reporter = new RateLimitedErrorReporter($this->fake, 60, true, 1000);
    $exception = new RuntimeException('Test');

    Log::shouldReceive('info')
        ->once()
        ->with(
            \Mockery::pattern('/^\[Serene\]/'),
            \Mockery::any()
        );

    $reporter->report($exception);
});

test('debug mode can be toggled per instance', function () {
    $debugReporter = new RateLimitedErrorReporter($this->fake, 60, true, 1000);
    $normalReporter = new RateLimitedErrorReporter($this->fake, 60, false, 1000);
    $exception1 = new RuntimeException('Test 1');
    $exception2 = new RuntimeException('Test 2');

    Log::shouldReceive('info')->once(); // Only debug reporter logs
    Log::shouldNotReceive('debug');

    $debugReporter->report($exception1); // Logs
    $normalReporter->report($exception2); // Does not log
});
