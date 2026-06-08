<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter;
use Nikolaynesov\LaravelSerene\Tests\Helpers\FakeErrorReporter;
use Nikolaynesov\LaravelSerene\Tests\Helpers\ReporterFactory;

beforeEach(function () {
    $this->fake = new FakeErrorReporter();
    $this->reporter = ReporterFactory::create($this->fake, 60, false, 1000);
    Carbon::setTestNow('2025-12-05 10:00:00');
    Cache::flush();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('reports error on first occurrence', function () {
    $exception = new RuntimeException('Test error');

    $this->reporter->report($exception);

    $this->fake->assertReportCount(1);
    $this->fake->assertReported(RuntimeException::class);
});

test('throttles duplicate errors within cooldown period', function () {
    $exception = new RuntimeException('Test error');

    $this->reporter->report($exception); // Reported
    $this->reporter->report($exception); // Throttled
    $this->reporter->report($exception); // Throttled

    $this->fake->assertReportCount(1);
});

test('reports error again after cooldown expires', function () {
    $exception = new RuntimeException('Test error');

    $this->reporter->report($exception);

    Carbon::setTestNow('2025-12-05 11:01:00'); // 61 minutes later

    $this->reporter->report($exception);

    $this->fake->assertReportCount(2);
});

test('tracks affected users correctly', function () {
    $exception = new RuntimeException('Test error');

    $this->reporter->report($exception, ['user_id' => 1]); // Reported
    $this->reporter->report($exception, ['user_id' => 2]); // Throttled
    $this->reporter->report($exception, ['user_id' => 3]); // Throttled

    Carbon::setTestNow('2025-12-05 11:01:00');

    $this->reporter->report($exception, ['user_id' => 4]); // Reported with stats

    $lastReport = $this->fake->getLastReport();

    expect($lastReport['context']['affected_users'])->toBe([4])
        ->and($lastReport['context']['affected_user_count'])->toBe(1);
});

test('does not duplicate user IDs in affected users list', function () {
    $exception = new RuntimeException('Test error');

    $this->reporter->report($exception, ['user_id' => 1]);
    $this->reporter->report($exception, ['user_id' => 1]); // Same user
    $this->reporter->report($exception, ['user_id' => 1]); // Same user

    Carbon::setTestNow('2025-12-05 11:01:00');

    $this->reporter->report($exception);

    $lastReport = $this->fake->getLastReport();

    // The same user appeared three times but is tracked once, not duplicated.
    expect($lastReport['context']['affected_users'])->toBe([1])
        ->and($lastReport['context']['affected_user_count'])->toBe(1);
});

test('increments occurrence counter', function () {
    $exception = new RuntimeException('Test error');

    $this->reporter->report($exception); // 1
    $this->reporter->report($exception); // 2
    $this->reporter->report($exception); // 3

    Carbon::setTestNow('2025-12-05 11:01:00');

    $this->reporter->report($exception); // 4

    $lastReport = $this->fake->getLastReport();

    expect($lastReport['context']['occurrences'])->toBe(4);
});

test('increments throttled counter', function () {
    $exception = new RuntimeException('Test error');

    $this->reporter->report($exception); // Reported (not throttled)
    $this->reporter->report($exception); // Throttled
    $this->reporter->report($exception); // Throttled

    Carbon::setTestNow('2025-12-05 11:01:00');

    $this->reporter->report($exception);

    $lastReport = $this->fake->getLastReport();

    expect($lastReport['context']['throttled'])->toBe(2);
});

test('adds correct context to reported errors', function () {
    $exception = new RuntimeException('Test error');

    $this->reporter->report($exception, ['custom' => 'value']);

    $lastReport = $this->fake->getLastReport();

    expect($lastReport['context'])->toHaveKeys([
        'affected_users',
        'affected_user_count',
        'reported_at',
        'key',
        'occurrences',
        'throttled',
        'custom',
    ])->and($lastReport['context']['custom'])->toBe('value');
});

test('clears user tracking after reporting', function () {
    $exception = new RuntimeException('Test error');

    $this->reporter->report($exception, ['user_id' => 1]);
    $this->reporter->report($exception, ['user_id' => 2]);

    Carbon::setTestNow('2025-12-05 11:01:00');

    $this->reporter->report($exception, ['user_id' => 3]);

    $lastReport = $this->fake->getLastReport();

    // Should only have user 3, previous users cleared
    expect($lastReport['context']['affected_users'])->toBe([3]);
});

test('handles custom error keys', function () {
    $exception = new RuntimeException('Test error');

    $this->reporter->report($exception, [], 'custom:key:123');
    $this->reporter->report($exception, [], 'custom:key:123'); // Throttled

    $this->fake->assertReportCount(1);
});

test('different custom keys are treated as different errors', function () {
    $exception = new RuntimeException('Same error');

    $this->reporter->report($exception, [], 'key:1');
    $this->reporter->report($exception, [], 'key:2');

    $this->fake->assertReportCount(2);
});

test('works without user_id in context', function () {
    $exception = new RuntimeException('Test error');

    $this->reporter->report($exception);
    $this->reporter->report($exception);

    $this->fake->assertReportCount(1);

    $lastReport = $this->fake->getLastReport();

    expect($lastReport['context']['affected_users'])->toBe([])
        ->and($lastReport['context']['affected_user_count'])->toBe(0);
});

test('handles different exceptions separately', function () {
    $exception1 = new RuntimeException('Error one');
    $exception2 = new InvalidArgumentException('Error two');

    $this->reporter->report($exception1);
    $this->reporter->report($exception1); // Throttled
    $this->reporter->report($exception2); // Different error, reported

    $this->fake->assertReportCount(2);
});

test('respects custom cooldown period', function () {
    $reporter = ReporterFactory::create($this->fake, 30, false, 1000); // 30 minutes
    $exception = new RuntimeException('Test error');

    $reporter->report($exception);

    Carbon::setTestNow('2025-12-05 10:29:00'); // 29 minutes later
    $reporter->report($exception); // Still throttled

    $this->fake->assertReportCount(1);

    Carbon::setTestNow('2025-12-05 10:31:00'); // 31 minutes later
    $reporter->report($exception); // Not throttled

    $this->fake->assertReportCount(2);
});

test('reported_at contains timestamp', function () {
    $exception = new RuntimeException('Test error');

    $this->reporter->report($exception);

    $lastReport = $this->fake->getLastReport();

    expect($lastReport['context']['reported_at'])->toBe('2025-12-05 10:00:00');
});

test('key is included in context', function () {
    $exception = new RuntimeException('Test error');

    $this->reporter->report($exception);

    $lastReport = $this->fake->getLastReport();

    expect($lastReport['context']['key'])->toMatch('/^auto:runtimeexception:/');
});

test('stats reset after cooldown', function () {
    $exception = new RuntimeException('Test error');

    $this->reporter->report($exception); // occurrences=1, throttled=0, reported
    $this->reporter->report($exception); // occurrences=2, throttled=1 (throttled)

    // Jump past the stats TTL (first_seen + cooldown(60m) + 10m buffer) with no
    // activity in between, so the cached stats expire and the next occurrence
    // starts a brand-new cycle rather than accumulating onto the old counts.
    Carbon::setTestNow('2025-12-05 11:11:00');

    $this->reporter->report($exception);

    $lastReport = $this->fake->getLastReport();

    expect($lastReport['context']['occurrences'])->toBe(1)
        ->and($lastReport['context']['throttled'])->toBe(0);
});
