<?php

use Illuminate\Support\Facades\Cache;
use Nikolaynesov\LaravelSerene\Contracts\GroupableException;
use Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter;
use Nikolaynesov\LaravelSerene\Tests\Helpers\FakeErrorReporter;
use Nikolaynesov\LaravelSerene\Tests\Helpers\ReporterFactory;

// Test exception that implements GroupableException
class TestGroupableException extends RuntimeException implements GroupableException
{
    public function __construct(string $message = '', private string $group = 'test-group')
    {
        parent::__construct($message);
    }

    public function getErrorGroup(): string
    {
        return $this->group;
    }
}

// Payment exception example
class PaymentException extends RuntimeException implements GroupableException
{
    public function getErrorGroup(): string
    {
        return 'payment-processing-error';
    }
}

// API exception with dynamic grouping
class ApiException extends RuntimeException implements GroupableException
{
    public function __construct(string $message, private string $endpoint)
    {
        parent::__construct($message);
    }

    public function getErrorGroup(): string
    {
        return "api:{$this->endpoint}";
    }
}

beforeEach(function () {
    Cache::flush();
});

test('uses exception getErrorGroup method when implemented', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake);

    $exception = new TestGroupableException('Test error', 'custom-group');
    $reporter->report($exception);

    $report = $fake->getLastReport();
    expect($report['context']['key'])->toBe('custom-group');
});

test('groups multiple errors with same group together', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake);

    // Report same group with different messages
    $reporter->report(new TestGroupableException('Error 1', 'payment-errors'));
    $reporter->report(new TestGroupableException('Error 2', 'payment-errors'));
    $reporter->report(new TestGroupableException('Error 3', 'payment-errors'));

    // Only first should be reported (throttled)
    expect($fake->reports)->toHaveCount(1);
});

test('different groups are not throttled together', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake);

    $reporter->report(new TestGroupableException('Error 1', 'group-a'));
    $reporter->report(new TestGroupableException('Error 2', 'group-b'));
    $reporter->report(new TestGroupableException('Error 3', 'group-c'));

    // All different groups should be reported
    expect($fake->reports)->toHaveCount(3);
});

test('explicit key parameter overrides getErrorGroup', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake);

    $exception = new TestGroupableException('Test', 'exception-group');
    $reporter->report($exception, [], 'explicit-override');

    $report = $fake->getLastReport();
    expect($report['context']['key'])->toBe('explicit-override');
});

test('non-groupable exceptions use auto-generated key', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake);

    $exception = new RuntimeException('Regular exception');
    $reporter->report($exception);

    $report = $fake->getLastReport();
    expect($report['context']['key'])->toStartWith('auto:runtimeexception:');
});

test('payment exception groups all instances together', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake);

    // Different messages but same group
    $reporter->report(new PaymentException('Failed for user 123'));
    $reporter->report(new PaymentException('Failed for user 456'));
    $reporter->report(new PaymentException('Failed for user 789'));

    // Only first should be reported
    expect($fake->reports)->toHaveCount(1);
    expect($fake->reports[0]['context']['key'])->toBe('payment-processing-error');
});

test('api exception groups by endpoint', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake);

    // Same endpoint, different messages
    $reporter->report(new ApiException('Timeout', 'stripe/charges'));
    $reporter->report(new ApiException('Invalid request', 'stripe/charges'));

    // Different endpoint
    $reporter->report(new ApiException('Failed', 'stripe/customers'));

    // stripe/charges should be throttled (1 report)
    // stripe/customers should be reported (1 report)
    expect($fake->reports)->toHaveCount(2);
    expect($fake->reports[0]['context']['key'])->toBe('api:stripe/charges');
    expect($fake->reports[1]['context']['key'])->toBe('api:stripe/customers');
});

test('tracks affected users correctly with groupable exceptions', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake);

    // First occurrence with user 100
    $reporter->report(new PaymentException('Error 1'), ['user_id' => 100]);

    // Throttled occurrences with different users
    $reporter->report(new PaymentException('Error 2'), ['user_id' => 200]);
    $reporter->report(new PaymentException('Error 3'), ['user_id' => 300]);

    // Only first should be reported
    expect($fake->reports)->toHaveCount(1);

    // First report shows only first user
    expect($fake->reports[0]['context']['affected_users'])->toBe([100]);

    // After cooldown, new occurrence starts fresh tracking
    $this->travel(31)->minutes();
    $reporter->report(new PaymentException('Error 4'), ['user_id' => 400]);

    expect($fake->reports)->toHaveCount(2);

    // Second report shows new user (tracking reset after cooldown)
    expect($fake->reports[1]['context']['affected_users'])->toBe([400]);
});

test('metrics accumulate with groupable exceptions', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake);

    // First occurrence
    $reporter->report(new TestGroupableException('Error 1', 'metrics-test'));
    expect($fake->reports[0]['context']['occurrences'])->toBe(1);
    expect($fake->reports[0]['context']['throttled'])->toBe(0);

    // Throttled occurrences
    $reporter->report(new TestGroupableException('Error 2', 'metrics-test'));
    $reporter->report(new TestGroupableException('Error 3', 'metrics-test'));

    // Still only 1 report (others throttled)
    expect($fake->reports)->toHaveCount(1);

    // Travel just past the cooldown (30m) but within the stats TTL
    // (first_seen + cooldown + 10m buffer). The next report accumulates the
    // counts gathered during the throttle window: 4 occurrences, 2 throttled.
    $this->travel(31)->minutes();
    $reporter->report(new TestGroupableException('Error 4', 'metrics-test'));

    $report = $fake->getLastReport();
    expect($report['context']['occurrences'])->toBe(4);
    expect($report['context']['throttled'])->toBe(2);

    // Travel past the stats TTL with no further activity. The cached stats
    // expire, so the next occurrence starts a fresh cycle.
    $this->travel(31)->minutes();
    $reporter->report(new TestGroupableException('Error 5', 'metrics-test'));

    $report = $fake->getLastReport();
    expect($report['context']['occurrences'])->toBe(1);
    expect($report['context']['throttled'])->toBe(0);
});

test('works with max_tracked_errors limit', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake, 30, false, 1000, 2);

    // Fill the limit with groupable exceptions
    $reporter->report(new TestGroupableException('Test', 'group-1'));
    $reporter->report(new TestGroupableException('Test', 'group-2'));

    // 3rd should bypass throttling
    $reporter->report(new TestGroupableException('Test', 'group-3'));

    expect($fake->reports)->toHaveCount(3);
    expect($fake->reports[2]['context']['tracking_limit_reached'])->toBeTrue();
});

test('explicit key still overrides on groupable exception', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake);

    $exception1 = new PaymentException('Error 1');
    $exception2 = new PaymentException('Error 2');

    // First with explicit key
    $reporter->report($exception1, [], 'manual-override');

    // Second with different explicit key
    $reporter->report($exception2, [], 'another-override');

    // Both should be reported (different keys)
    expect($fake->reports)->toHaveCount(2);
    expect($fake->reports[0]['context']['key'])->toBe('manual-override');
    expect($fake->reports[1]['context']['key'])->toBe('another-override');
});

test('mixed groupable and non-groupable exceptions work together', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake);

    $reporter->report(new PaymentException('Payment failed'));
    $reporter->report(new RuntimeException('Regular error'));
    $reporter->report(new ApiException('API error', 'stripe/charges'));

    // All different groups, all should be reported
    expect($fake->reports)->toHaveCount(3);
    expect($fake->reports[0]['context']['key'])->toBe('payment-processing-error');
    expect($fake->reports[1]['context']['key'])->toStartWith('auto:runtimeexception:');
    expect($fake->reports[2]['context']['key'])->toBe('api:stripe/charges');
});

test('groupable exception throttles on cooldown expiry', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake, 1);

    // First report
    $reporter->report(new PaymentException('Error 1'));

    // Throttled
    $reporter->report(new PaymentException('Error 2'));

    expect($fake->reports)->toHaveCount(1);

    // Travel past cooldown
    $this->travel(2)->minutes();

    // Should be reported again
    $reporter->report(new PaymentException('Error 3'));

    expect($fake->reports)->toHaveCount(2);
});

test('getErrorGroup can return complex identifiers', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake);

    $exception = new ApiException('Test', 'payments/v2/stripe/charges');
    $reporter->report($exception);

    $report = $fake->getLastReport();
    expect($report['context']['key'])->toBe('api:payments/v2/stripe/charges');
});

test('empty getErrorGroup falls back to auto-generation', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake);

    $exception = new class('Test') extends RuntimeException implements GroupableException {
        public function getErrorGroup(): string
        {
            return ''; // Empty string
        }
    };

    $reporter->report($exception);

    $report = $fake->getLastReport();
    // Empty group is treated as "no grouping" and falls back to the
    // auto-generated key derived from the exception class and message.
    expect($report['context']['key'])
        ->toBe(\Nikolaynesov\LaravelSerene\Support\KeyGenerator::fromException($exception));
});

test('hierarchy: explicit > groupable > auto', function () {
    $fake = new FakeErrorReporter();
    $reporter = ReporterFactory::create($fake);

    $groupableException = new TestGroupableException('Test', 'from-exception');
    $regularException = new RuntimeException('Regular');

    // Test 1: Explicit key on groupable exception
    $reporter->report($groupableException, [], 'explicit-key');
    expect($fake->reports[0]['context']['key'])->toBe('explicit-key');

    Cache::flush();

    // Test 2: Groupable exception without explicit key
    $reporter->report($groupableException);
    expect($fake->reports[1]['context']['key'])->toBe('from-exception');

    Cache::flush();

    // Test 3: Regular exception with explicit key
    $reporter->report($regularException, [], 'explicit-regular');
    expect($fake->reports[2]['context']['key'])->toBe('explicit-regular');

    Cache::flush();

    // Test 4: Regular exception without explicit key (auto-generated)
    $reporter->report($regularException);
    expect($fake->reports[3]['context']['key'])->toStartWith('auto:runtimeexception:');
});
