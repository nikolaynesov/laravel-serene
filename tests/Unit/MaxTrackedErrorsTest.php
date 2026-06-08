<?php

use Illuminate\Support\Facades\Cache;
use Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter;
use Nikolaynesov\LaravelSerene\Tests\Helpers\FakeErrorReporter;
use Nikolaynesov\LaravelSerene\Tests\Helpers\ReporterFactory;

beforeEach(function () {
    Cache::flush();
});

test('allows errors up to max_tracked_errors limit', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake, 30, false, 1000, 3);

    // Report 3 different errors (at limit)
    $reporter->report(new RuntimeException('Error 1'));
    $reporter->report(new RuntimeException('Error 2'));
    $reporter->report(new RuntimeException('Error 3'));

    // All 3 should be reported
    expect($fake->reports)->toHaveCount(3);
});

test('bypasses throttling when max_tracked_errors limit is reached', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake, 30, false, 1000, 3);

    // Report 3 different errors to reach limit
    $reporter->report(new RuntimeException('Error 1'));
    $reporter->report(new RuntimeException('Error 2'));
    $reporter->report(new RuntimeException('Error 3'));

    // 4th error should bypass throttling and report immediately
    $reporter->report(new RuntimeException('Error 4'));

    expect($fake->reports)->toHaveCount(4);

    // Verify the 4th error has the tracking_limit_reached flag
    expect($fake->reports[3]['context'])->toHaveKey('tracking_limit_reached', true);
});

test('duplicate errors still throttle when under limit', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake, 30, false, 1000, 10);

    // Report same error 5 times
    for ($i = 0; $i < 5; $i++) {
        $reporter->report(new RuntimeException('Same error'));
    }

    // Only first occurrence should be reported (throttling works)
    expect($fake->reports)->toHaveCount(1);
});

test('tracking limit does not affect existing throttled errors', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake, 30, false, 1000, 2);

    // Report 2 unique errors to reach limit
    $reporter->report(new RuntimeException('Error 1'));
    $reporter->report(new RuntimeException('Error 2'));

    // Report duplicate of Error 1 (should still be throttled)
    $reporter->report(new RuntimeException('Error 1'));

    // Should still be 2 reports (duplicate was throttled)
    expect($fake->reports)->toHaveCount(2);
});

test('reports immediately without throttling when at limit', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake, 30, false, 1000, 2);

    // Fill the limit
    $reporter->report(new RuntimeException('Error 1'));
    $reporter->report(new RuntimeException('Error 2'));

    // New error should be reported immediately
    $reporter->report(new RuntimeException('Error 3'));

    // All 3 should be reported
    expect($fake->reports)->toHaveCount(3);

    // Verify Error 3 has tracking_limit_reached flag
    expect($fake->reports[2]['context']['tracking_limit_reached'])->toBeTrue();
});

test('reports immediately multiple times when at limit', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake, 30, false, 1000, 2);

    // Fill the limit
    $reporter->report(new RuntimeException('Error 1'));
    $reporter->report(new RuntimeException('Error 2'));

    // Report 3 more unique errors (all should bypass throttling)
    $reporter->report(new RuntimeException('Error 3'));
    $reporter->report(new RuntimeException('Error 4'));
    $reporter->report(new RuntimeException('Error 5'));

    // All 5 should be reported (no throttling for errors 3-5)
    expect($fake->reports)->toHaveCount(5);
});

test('expired errors are cleaned up from tracking', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake, 30, false, 1000, 2);

    // Report 2 errors to fill limit
    $reporter->report(new RuntimeException('Error 1'));
    $reporter->report(new RuntimeException('Error 2'));

    // Simulate cooldown expiry
    $this->travel(31)->minutes();

    // New error should now be throttled (not bypass)
    $reporter->report(new RuntimeException('Error 3'));

    // Should have 3 reports
    expect($fake->reports)->toHaveCount(3);

    // Error 3 should NOT have tracking_limit_reached flag (throttling resumed)
    expect($fake->reports[2]['context'])->not->toHaveKey('tracking_limit_reached');
});

test('includes key in context when tracking limit is reached', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake, 30, false, 1000, 1);

    // Fill the limit
    $reporter->report(new RuntimeException('Error 1'));

    // Bypass throttling
    $reporter->report(new RuntimeException('Error 2'));

    expect($fake->reports[1]['context'])->toHaveKey('key');
    expect($fake->reports[1]['context'])->toHaveKey('reported_at');
    expect($fake->reports[1]['context']['tracking_limit_reached'])->toBeTrue();
});

test('high max_tracked_errors value allows many unique errors', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake, 30, false, 1000, 100);

    // Report 50 unique errors
    for ($i = 1; $i <= 50; $i++) {
        $reporter->report(new RuntimeException("Error {$i}"));
    }

    // All 50 should be tracked and reported
    expect($fake->reports)->toHaveCount(50);

    // None should have tracking_limit_reached flag
    foreach ($fake->reports as $report) {
        expect($report['context'])->not->toHaveKey('tracking_limit_reached');
    }
});

test('custom keys work with max_tracked_errors limit', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake, 30, false, 1000, 2);

    // Report 2 errors with custom keys
    $reporter->report(new RuntimeException('Test'), [], 'custom-key-1');
    $reporter->report(new RuntimeException('Test'), [], 'custom-key-2');

    // 3rd error should bypass
    $reporter->report(new RuntimeException('Test'), [], 'custom-key-3');

    expect($fake->reports)->toHaveCount(3);
    expect($fake->reports[2]['context']['tracking_limit_reached'])->toBeTrue();
});

test('same custom key reuses same tracking slot', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake, 30, false, 1000, 2);

    // Report 2 errors with different keys
    $reporter->report(new RuntimeException('Test 1'), [], 'custom-key-1');
    $reporter->report(new RuntimeException('Test 2'), [], 'custom-key-2');

    // Report again with custom-key-1 (should be throttled)
    $reporter->report(new RuntimeException('Test 1'), [], 'custom-key-1');

    // Should still be 2 reports (duplicate was throttled)
    expect($fake->reports)->toHaveCount(2);
});

test('global tracking array auto-cleans expired entries', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake, 1, false, 1000, 5); // 1 minute cooldown

    // Report 3 errors
    $reporter->report(new RuntimeException('Error 1'));
    $reporter->report(new RuntimeException('Error 2'));
    $reporter->report(new RuntimeException('Error 3'));

    // Travel forward to expire first 2 errors
    $this->travel(2)->minutes();

    // Report 2 more errors (should work because old ones expired)
    $reporter->report(new RuntimeException('Error 4'));
    $reporter->report(new RuntimeException('Error 5'));

    // All 5 should be reported
    expect($fake->reports)->toHaveCount(5);

    // None should have tracking_limit_reached
    foreach ($fake->reports as $report) {
        expect($report['context'])->not->toHaveKey('tracking_limit_reached');
    }
});

test('validates max_tracked_errors in service provider', function () {
    config(['serene.max_tracked_errors' => -1]);

    expect(fn() => app()->make(\Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter::class))
        ->toThrow(\InvalidArgumentException::class, 'Max tracked errors must be a positive integer');
});

test('validates max_tracked_errors is integer in service provider', function () {
    config(['serene.max_tracked_errors' => '100']);

    expect(fn() => app()->make(\Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter::class))
        ->toThrow(\InvalidArgumentException::class, 'Max tracked errors must be a positive integer');
});
