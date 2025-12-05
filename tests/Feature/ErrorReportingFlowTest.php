<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Nikolaynesov\LaravelSerene\Facades\Serene;
use Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter;
use Nikolaynesov\LaravelSerene\Tests\Helpers\FakeErrorReporter;

beforeEach(function () {
    Carbon::setTestNow('2025-12-05 10:00:00');
    Cache::flush();

    // Bind FakeErrorReporter for testing
    $this->fake = new FakeErrorReporter();
    config(['serene.provider' => get_class($this->fake)]);

    $this->app->bind(get_class($this->fake), fn () => $this->fake);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('complete flow from exception to reporting', function () {
    $exception = new RuntimeException('Database connection failed');

    Serene::report($exception, ['user_id' => 1]);

    $this->fake->assertReportCount(1);
    $this->fake->assertReported(RuntimeException::class);
});

test('multiple errors with same signature are throttled', function () {
    $makeException = fn () => new RuntimeException('Same error');

    Serene::report($makeException()); // Reported
    Serene::report($makeException()); // Throttled
    Serene::report($makeException()); // Throttled
    Serene::report($makeException()); // Throttled

    $this->fake->assertReportCount(1);
});

test('different errors are reported separately', function () {
    Serene::report(new RuntimeException('Error A'));
    Serene::report(new RuntimeException('Error B'));
    Serene::report(new InvalidArgumentException('Error A'));

    $this->fake->assertReportCount(3);
});

test('cooldown period expires correctly', function () {
    $exception = new RuntimeException('Periodic error');

    Serene::report($exception); // Reported at 10:00

    Carbon::setTestNow('2025-12-05 10:59:00');
    Serene::report($exception); // Throttled at 10:59

    $this->fake->assertReportCount(1);

    Carbon::setTestNow('2025-12-05 11:01:00');
    Serene::report($exception); // Reported at 11:01

    $this->fake->assertReportCount(2);
});

test('user tracking across multiple errors', function () {
    $exception = new RuntimeException('User error');

    Serene::report($exception, ['user_id' => 1]); // Reported
    Serene::report($exception, ['user_id' => 2]); // Throttled
    Serene::report($exception, ['user_id' => 3]); // Throttled
    Serene::report($exception, ['user_id' => 2]); // Duplicate user, throttled

    Carbon::setTestNow('2025-12-05 11:01:00');

    Serene::report($exception, ['user_id' => 4]); // Reported

    $lastReport = $this->fake->getLastReport();

    // Previous users cleared, only new user tracked
    expect($lastReport['context']['affected_users'])->toBe([4])
        ->and($lastReport['context']['affected_user_count'])->toBe(1);
});

test('metrics accumulate correctly over time', function () {
    $exception = new RuntimeException('Metric test');

    // First reporting cycle
    Serene::report($exception); // occurrence 1, reported
    Serene::report($exception); // occurrence 2, throttled
    Serene::report($exception); // occurrence 3, throttled

    Carbon::setTestNow('2025-12-05 11:01:00');

    Serene::report($exception); // occurrence 4, reported

    $report = $this->fake->getLastReport();

    expect($report['context']['occurrences'])->toBe(4)
        ->and($report['context']['throttled'])->toBe(2);
});

test('works with different cache drivers', function () {
    // Test with array cache (default)
    config(['cache.default' => 'array']);

    $exception = new RuntimeException('Cache test');

    Serene::report($exception);
    Serene::report($exception);

    $this->fake->assertReportCount(1);
});

test('handles concurrent errors from different sources', function () {
    $error1 = new RuntimeException('API Error');
    $error2 = new RuntimeException('Database Error');
    $error3 = new InvalidArgumentException('Validation Error');

    Serene::report($error1, ['source' => 'api']);
    Serene::report($error2, ['source' => 'db']);
    Serene::report($error1, ['source' => 'api']); // Throttled
    Serene::report($error3, ['source' => 'validation']);
    Serene::report($error2, ['source' => 'db']); // Throttled

    $this->fake->assertReportCount(3);
});

test('custom context is preserved through throttling', function () {
    $exception = new RuntimeException('Context test');

    Serene::report($exception, ['request_id' => 'abc123']);
    Serene::report($exception, ['request_id' => 'def456']); // Throttled

    Carbon::setTestNow('2025-12-05 11:01:00');

    Serene::report($exception, ['request_id' => 'ghi789']);

    $lastReport = $this->fake->getLastReport();

    expect($lastReport['context']['request_id'])->toBe('ghi789');
});

test('reports include all expected metadata', function () {
    $exception = new RuntimeException('Metadata test');

    Serene::report($exception, ['user_id' => 1, 'custom' => 'value']);

    Carbon::setTestNow('2025-12-05 11:01:00');

    Serene::report($exception);

    $report = $this->fake->getLastReport();

    expect($report['context'])->toHaveKeys([
        'affected_users',
        'affected_user_count',
        'reported_at',
        'key',
        'occurrences',
        'throttled',
    ])->and($report['context']['reported_at'])->toBe('2025-12-05 11:01:00')
        ->and($report['context']['key'])->toBeString();
});

test('dependency injection works', function () {
    $reporter = app(RateLimitedErrorReporter::class);

    expect($reporter)->toBeInstanceOf(RateLimitedErrorReporter::class);

    $exception = new RuntimeException('DI test');

    $reporter->report($exception);

    $this->fake->assertReportCount(1);
});
