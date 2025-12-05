<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

test('tracks users up to max limit', function () {
    $reporter = new RateLimitedErrorReporter($this->fake, 60, false, 10);
    $exception = new RuntimeException('Test');

    // Add 10 users - should all be tracked
    for ($i = 1; $i <= 10; $i++) {
        $reporter->report($exception, ['user_id' => $i]);
    }

    Carbon::setTestNow('2025-12-05 11:01:00');

    $reporter->report($exception);

    $lastReport = $this->fake->getLastReport();

    expect($lastReport['context']['affected_user_count'])->toBe(10)
        ->and($lastReport['context']['affected_users'])->toHaveCount(10);
});

test('stops tracking after reaching max limit', function () {
    $reporter = new RateLimitedErrorReporter($this->fake, 60, false, 10);
    $exception = new RuntimeException('Test');

    // Add 15 users - only first 10 should be tracked
    for ($i = 1; $i <= 15; $i++) {
        $reporter->report($exception, ['user_id' => $i]);
    }

    Carbon::setTestNow('2025-12-05 11:01:00');

    $reporter->report($exception);

    $lastReport = $this->fake->getLastReport();

    expect($lastReport['context']['affected_user_count'])->toBe(10)
        ->and($lastReport['context']['affected_users'])->toHaveCount(10)
        ->and($lastReport['context']['affected_users'])->toBe([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
});

test('user_tracking_capped is false when under limit', function () {
    $reporter = new RateLimitedErrorReporter($this->fake, 60, false, 100);
    $exception = new RuntimeException('Test');

    $reporter->report($exception, ['user_id' => 1]);
    $reporter->report($exception, ['user_id' => 2]);

    Carbon::setTestNow('2025-12-05 11:01:00');

    $reporter->report($exception);

    $lastReport = $this->fake->getLastReport();

    expect($lastReport['context']['user_tracking_capped'])->toBe(false);
});

test('user_tracking_capped is true when at max limit', function () {
    $reporter = new RateLimitedErrorReporter($this->fake, 60, false, 5);
    $exception = new RuntimeException('Test');

    for ($i = 1; $i <= 5; $i++) {
        $reporter->report($exception, ['user_id' => $i]);
    }

    Carbon::setTestNow('2025-12-05 11:01:00');

    $reporter->report($exception);

    $lastReport = $this->fake->getLastReport();

    expect($lastReport['context']['user_tracking_capped'])->toBe(true);
});

test('user_tracking_capped is true when over max limit', function () {
    $reporter = new RateLimitedErrorReporter($this->fake, 60, false, 5);
    $exception = new RuntimeException('Test');

    for ($i = 1; $i <= 10; $i++) {
        $reporter->report($exception, ['user_id' => $i]);
    }

    Carbon::setTestNow('2025-12-05 11:01:00');

    $reporter->report($exception);

    $lastReport = $this->fake->getLastReport();

    expect($lastReport['context']['user_tracking_capped'])->toBe(true)
        ->and($lastReport['context']['affected_user_count'])->toBe(5);
});

test('does not track duplicate users against the cap', function () {
    $reporter = new RateLimitedErrorReporter($this->fake, 60, false, 5);
    $exception = new RuntimeException('Test');

    // Add users 1, 2, 3, 1, 2, 3, 1, 2, 3
    for ($i = 0; $i < 9; $i++) {
        $reporter->report($exception, ['user_id' => ($i % 3) + 1]);
    }

    Carbon::setTestNow('2025-12-05 11:01:00');

    $reporter->report($exception);

    $lastReport = $this->fake->getLastReport();

    // Only 3 unique users should be tracked
    expect($lastReport['context']['affected_user_count'])->toBe(3)
        ->and($lastReport['context']['affected_users'])->toBe([1, 2, 3])
        ->and($lastReport['context']['user_tracking_capped'])->toBe(false);
});

test('respects default max of 1000 users', function () {
    $reporter = new RateLimitedErrorReporter($this->fake, 60, false); // Default 1000
    $exception = new RuntimeException('Test');

    // Add 1000 users
    for ($i = 1; $i <= 1000; $i++) {
        $reporter->report($exception, ['user_id' => $i]);
    }

    Carbon::setTestNow('2025-12-05 11:01:00');

    $reporter->report($exception);

    $lastReport = $this->fake->getLastReport();

    expect($lastReport['context']['affected_user_count'])->toBe(1000)
        ->and($lastReport['context']['user_tracking_capped'])->toBe(true);
});

test('max limit can be configured per instance', function () {
    $reporter1 = new RateLimitedErrorReporter($this->fake, 60, false, 5);
    $reporter2 = new RateLimitedErrorReporter($this->fake, 60, false, 10);

    $exception1 = new RuntimeException('Test 1');
    $exception2 = new RuntimeException('Test 2');

    // Reporter 1: add 10 users (cap at 5)
    for ($i = 1; $i <= 10; $i++) {
        $reporter1->report($exception1, ['user_id' => $i]);
    }

    // Reporter 2: add 10 users (cap at 10)
    for ($i = 1; $i <= 10; $i++) {
        $reporter2->report($exception2, ['user_id' => $i]);
    }

    Carbon::setTestNow('2025-12-05 11:01:00');

    $reporter1->report($exception1);
    $reporter2->report($exception2);

    expect($this->fake->reports[0]['context']['affected_user_count'])->toBe(5)
        ->and($this->fake->reports[1]['context']['affected_user_count'])->toBe(10);
});

test('cap resets after cooldown period', function () {
    $reporter = new RateLimitedErrorReporter($this->fake, 60, false, 5);
    $exception = new RuntimeException('Test');

    // First cycle: add 5 users
    for ($i = 1; $i <= 5; $i++) {
        $reporter->report($exception, ['user_id' => $i]);
    }

    Carbon::setTestNow('2025-12-05 11:01:00');

    $reporter->report($exception); // Reported, clears cache

    // Second cycle: add 5 new users
    for ($i = 6; $i <= 10; $i++) {
        $reporter->report($exception, ['user_id' => $i]);
    }

    Carbon::setTestNow('2025-12-05 12:02:00');

    $reporter->report($exception);

    $lastReport = $this->fake->getLastReport();

    // Should have users 6-10 from second cycle
    expect($lastReport['context']['affected_users'])->toBe([6, 7, 8, 9, 10])
        ->and($lastReport['context']['affected_user_count'])->toBe(5)
        ->and($lastReport['context']['user_tracking_capped'])->toBe(true);
});

test('tracks no users when user_id not provided', function () {
    $reporter = new RateLimitedErrorReporter($this->fake, 60, false, 10);
    $exception = new RuntimeException('Test');

    $reporter->report($exception);

    $lastReport = $this->fake->getLastReport();

    expect($lastReport['context']['affected_users'])->toBe([])
        ->and($lastReport['context']['affected_user_count'])->toBe(0)
        ->and($lastReport['context']['user_tracking_capped'])->toBe(false);
});

test('handles mixed errors with different user caps independently', function () {
    $reporter = new RateLimitedErrorReporter($this->fake, 60, false, 3);
    $exception1 = new RuntimeException('Error A');
    $exception2 = new RuntimeException('Error B');

    // Error A: add 5 users (cap at 3)
    for ($i = 1; $i <= 5; $i++) {
        $reporter->report($exception1, ['user_id' => $i]);
    }

    // Error B: add 2 users
    $reporter->report($exception2, ['user_id' => 10]);
    $reporter->report($exception2, ['user_id' => 20]);

    Carbon::setTestNow('2025-12-05 11:01:00');

    $reporter->report($exception1);
    $reporter->report($exception2);

    // Error A should be capped
    expect($this->fake->reports[0]['context']['affected_user_count'])->toBe(3)
        ->and($this->fake->reports[0]['context']['user_tracking_capped'])->toBe(true);

    // Error B should not be capped
    expect($this->fake->reports[1]['context']['affected_user_count'])->toBe(2)
        ->and($this->fake->reports[1]['context']['user_tracking_capped'])->toBe(false);
});
