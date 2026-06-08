<?php

use Illuminate\Support\Facades\Cache;
use Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter;
use Nikolaynesov\LaravelSerene\Tests\Helpers\FakeErrorReporter;
use Nikolaynesov\LaravelSerene\Tests\Helpers\ReporterFactory;

beforeEach(function () {
    Cache::flush();
    $this->fake = new FakeErrorReporter();
});

test('flushThrottledErrors returns empty array when no throttled errors', function () {
    $reporter = ReporterFactory::create($this->fake);

    $flushed = $reporter->flushThrottledErrors();

    expect($flushed)->toBeEmpty();
});

test('flushThrottledErrors finds and reports throttled errors', function () {
    $reporter = ReporterFactory::create($this->fake);

    // Create throttled error
    $exception = new RuntimeException('Test error');
    $reporter->report($exception, ['user_id' => 1]); // Reported (clears cache)
    $reporter->report($exception, ['user_id' => 2]); // Throttled (tracked in cache)
    $reporter->report($exception, ['user_id' => 3]); // Throttled (tracked in cache)

    // Should have 1 report so far
    expect($this->fake->reports)->toHaveCount(1);

    // Flush throttled errors
    $flushed = $reporter->flushThrottledErrors();

    // Should flush 1 error (with throttled data only)
    expect($flushed)->toHaveCount(1);
    expect($flushed[0]['throttled'])->toBe(2);

    // Should have 2 reports now (original + flushed)
    expect($this->fake->reports)->toHaveCount(2);
});

test('flushed error includes accumulated statistics', function () {
    $reporter = ReporterFactory::create($this->fake);

    $exception = new RuntimeException('Test');
    $reporter->report($exception, ['user_id' => 100]); // Reported, starts accumulation
    $reporter->report($exception, ['user_id' => 200]); // Throttled
    $reporter->report($exception, ['user_id' => 300]); // Throttled

    $flushed = $reporter->flushThrottledErrors();

    expect($flushed[0])->toHaveKeys(['key', 'occurrences', 'throttled', 'affected_users']);
    // All users from throttle period are tracked (100, 200, 300)
    expect($flushed[0]['affected_users'])->toBe([100, 200, 300]);
});

test('flushed error is reported with correct context', function () {
    $reporter = ReporterFactory::create($this->fake);

    $exception = new RuntimeException('Test');
    $reporter->report($exception, ['user_id' => 1]); // Reported, starts accumulation
    $reporter->report($exception, ['user_id' => 2]); // Throttled

    $reporter->flushThrottledErrors();

    $flushedReport = $this->fake->reports[1];

    expect($flushedReport['context'])->toHaveKey('flushed_by_command', true);
    expect($flushedReport['context']['throttled'])->toBe(1);
    // All users from throttle period are tracked (1, 2)
    expect($flushedReport['context']['affected_users'])->toBe([1, 2]);
});

test('flush clears throttle markers', function () {
    $reporter = ReporterFactory::create($this->fake);

    $exception = new RuntimeException('Test');
    $reporter->report($exception);
    $reporter->report($exception);

    $flushed = $reporter->flushThrottledErrors();

    // Should have flushed
    expect($flushed)->toHaveCount(1);

    // Verify throttle was cleared by checking we can report again immediately
    $reporter->report($exception);

    // Should have 3 total reports (1st, flushed, 3rd after flush)
    expect($this->fake->reports)->toHaveCount(3);
});

test('flush handles multiple different throttled errors', function () {
    $reporter = ReporterFactory::create($this->fake);

    // Create 3 different throttled errors
    $reporter->report(new RuntimeException('Error 1'));
    $reporter->report(new RuntimeException('Error 1')); // Throttled

    $reporter->report(new RuntimeException('Error 2'));
    $reporter->report(new RuntimeException('Error 2')); // Throttled

    $reporter->report(new RuntimeException('Error 3'));
    $reporter->report(new RuntimeException('Error 3')); // Throttled

    // Should have 3 initial reports
    expect($this->fake->reports)->toHaveCount(3);

    $flushed = $reporter->flushThrottledErrors();

    // Should flush all 3
    expect($flushed)->toHaveCount(3);

    // Should have 6 total reports (3 initial + 3 flushed)
    expect($this->fake->reports)->toHaveCount(6);
});

test('dry-run mode does not report errors', function () {
    $reporter = ReporterFactory::create($this->fake);

    $exception = new RuntimeException('Test');
    $reporter->report($exception);
    $reporter->report($exception); // Throttled

    // Dry-run
    $flushed = $reporter->flushThrottledErrors(dryRun: true);

    // Should return what would be flushed
    expect($flushed)->toHaveCount(1);
    expect($flushed[0]['throttled'])->toBe(1);

    // Should NOT actually report (still only 1 report)
    expect($this->fake->reports)->toHaveCount(1);

    // Verify throttle is still active by reporting again (should be throttled)
    $reporter->report($exception);
    expect($this->fake->reports)->toHaveCount(1); // Still only 1 (throttled)
});

test('dry-run mode preserves cache state', function () {
    $reporter = ReporterFactory::create($this->fake);

    $exception = new RuntimeException('Test');
    $reporter->report($exception, ['user_id' => 1]);
    $reporter->report($exception, ['user_id' => 2]);

    // Get stats before dry-run
    $statsBefore = Cache::get('error-throttler:auto:runtimeexception:test:stats');

    $reporter->flushThrottledErrors(dryRun: true);

    // Stats should be unchanged
    $statsAfter = Cache::get('error-throttler:auto:runtimeexception:test:stats');
    expect($statsAfter)->toBe($statsBefore);
});

test('flush skips errors with no throttled occurrences', function () {
    $reporter = ReporterFactory::create($this->fake);

    // Report error only once (no throttling)
    $reporter->report(new RuntimeException('Test'));

    // Manually create a throttle marker but no stats
    Cache::put('error-throttler:custom-key', true, now()->addMinutes(30));

    $flushed = $reporter->flushThrottledErrors();

    // Should not flush errors with 0 throttled count
    expect($flushed)->toBeEmpty();
});

test('flush works with custom error keys', function () {
    $reporter = ReporterFactory::create($this->fake);

    $reporter->report(new RuntimeException('Test'), [], 'custom-key');
    $reporter->report(new RuntimeException('Test'), [], 'custom-key'); // Throttled

    $flushed = $reporter->flushThrottledErrors();

    expect($flushed)->toHaveCount(1);
    expect($flushed[0]['key'])->toBe('custom-key');
});

test('flush command can be run via artisan', function () {
    // Use the bound reporter from container
    $reporter = app(RateLimitedErrorReporter::class);

    // Create throttled error
    $reporter->report(new RuntimeException('Test'));
    $reporter->report(new RuntimeException('Test')); // Throttled

    // Run command
    $this->artisan('serene:flush-throttled')
        ->assertSuccessful()
        ->expectsOutput('Scanning for throttled errors...')
        ->expectsOutputToContain('Flushed 1 throttled error(s)');
});

test('flush command dry-run shows what would be flushed', function () {
    $reporter = ReporterFactory::create($this->fake);

    $reporter->report(new RuntimeException('Test'));
    $reporter->report(new RuntimeException('Test')); // Throttled

    $this->artisan('serene:flush-throttled', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutput('Running in dry-run mode (no errors will be reported)')
        ->expectsOutputToContain('Flushed 1 throttled error(s)')
        ->expectsOutput('Dry-run mode: No errors were actually reported.');

    // Should NOT have actually reported (still only 1 report)
    expect($this->fake->reports)->toHaveCount(1);
});

test('flush command handles no throttled errors gracefully', function () {
    $this->artisan('serene:flush-throttled')
        ->assertSuccessful()
        ->expectsOutput('No throttled errors found.');
});

test('flushed error removes from global tracking', function () {
    $reporter = ReporterFactory::create($this->fake);

    $reporter->report(new RuntimeException('Test'));
    $reporter->report(new RuntimeException('Test')); // Throttled

    // Should be in global tracking
    $trackedBefore = Cache::get('serene:global:tracked_errors', []);
    expect($trackedBefore)->not->toBeEmpty();

    $reporter->flushThrottledErrors();

    // Should be removed from global tracking
    $trackedAfter = Cache::get('serene:global:tracked_errors', []);
    expect($trackedAfter)->toBeEmpty();
});

test('flush respects max_tracked_users cap', function () {
    $reporter = ReporterFactory::create($this->fake, 30, false, 5);

    $exception = new RuntimeException('Test');
    $reporter->report($exception, ['user_id' => 1]);

    // Add 10 users (only 5 should be tracked due to cap)
    for ($i = 2; $i <= 10; $i++) {
        $reporter->report($exception, ['user_id' => $i]);
    }

    $flushed = $reporter->flushThrottledErrors();

    // Should have 5 users (capped)
    expect($flushed[0]['affected_users'])->toHaveCount(5);
});
