<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter;
use Nikolaynesov\LaravelSerene\Tests\Helpers\FakeErrorReporter;
use Nikolaynesov\LaravelSerene\Tests\Helpers\ReporterFactory;

beforeEach(function () {
    Carbon::setTestNow('2025-12-05 10:00:00');
    Cache::flush();
    $this->fake = new FakeErrorReporter();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('debug mode disabled does not log when error is reported', function () {
    config(['serene.debug' => false]);
    $reporter = ReporterFactory::create($this->fake, 60, false, 1000);
    $exception = new RuntimeException('Test');

    $spy = Log::spy();

    $reporter->report($exception);

    $spy->shouldNotHaveReceived('info');
    $spy->shouldNotHaveReceived('debug');
});

test('debug mode disabled does not log when error is throttled', function () {
    config(['serene.debug' => false]);
    $reporter = ReporterFactory::create($this->fake, 60, false, 1000);
    $exception = new RuntimeException('Test');

    $reporter->report($exception); // Reported

    $spy = Log::spy();

    $reporter->report($exception); // Throttled, should not log

    $spy->shouldNotHaveReceived('debug');
});

test('debug mode enabled logs when error is reported', function () {
    config(['serene.debug' => true]);
    $reporter = ReporterFactory::create($this->fake, 60, true, 1000);
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
    config(['serene.debug' => true]);
    $reporter = ReporterFactory::create($this->fake, 60, true, 1000);
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
    config(['serene.debug' => true]);
    $reporter = ReporterFactory::create($this->fake, 60, true, 1000);
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
    config(['serene.debug' => true]);
    $reporter = ReporterFactory::create($this->fake, 60, true, 1000);
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
    config(['serene.debug' => true]);
    $reporter = ReporterFactory::create($this->fake, 60, true, 1000);
    $exception = new RuntimeException('Test');

    Log::shouldReceive('info')->once();
    Log::shouldNotReceive('warning');
    Log::shouldNotReceive('error');

    $reporter->report($exception);
});

test('debug mode uses debug level for throttles', function () {
    config(['serene.debug' => true]);
    $reporter = ReporterFactory::create($this->fake, 60, true, 1000);
    $exception = new RuntimeException('Test');

    Log::shouldReceive('info')->once(); // First report

    $reporter->report($exception);

    Log::shouldReceive('debug')->once();
    Log::shouldNotReceive('warning');
    Log::shouldNotReceive('error');

    $reporter->report($exception); // Throttled
});

test('debug logs use Serene prefix', function () {
    config(['serene.debug' => true]);
    $reporter = ReporterFactory::create($this->fake, 60, true, 1000);
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
    config(['serene.debug' => true]);
    $debugReporter = ReporterFactory::create($this->fake, 60, true, 1000);

    config(['serene.debug' => false]);
    $normalReporter = ReporterFactory::create($this->fake, 60, false, 1000);

    $exception1 = new RuntimeException('Test 1');
    $exception2 = new RuntimeException('Test 2');

    // Since both use config, only first one should log
    config(['serene.debug' => true]);

    Log::shouldReceive('info')->once();

    $debugReporter->report($exception1); // Logs because config is true

    config(['serene.debug' => false]);

    $normalReporter->report($exception2); // Does not log because config is false
});
