<?php

use Bugsnag\BugsnagLaravel\Facades\Bugsnag;
use Nikolaynesov\LaravelSerene\Contracts\GroupableException;
use Nikolaynesov\LaravelSerene\Providers\BugsnagReporter;
use Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter;
use Nikolaynesov\LaravelSerene\Support\KeyGenerator;

/**
 * A minimal report spy that records the grouping hash set on it.
 */
function makeGroupingHashSpy(): object
{
    return new class {
        public ?string $groupingHash = null;
        public int $setGroupingHashCalls = 0;
        public array $metadata = [];
        public ?array $user = null;

        public function setGroupingHash(string $hash): void
        {
            $this->groupingHash = $hash;
            $this->setGroupingHashCalls++;
        }

        public function setUser(array $data): void
        {
            $this->user = $data;
        }

        public function setSeverity(string $severity): void {}

        public function addMetaData(string $tab, array $data): void {}

        public function setMetaData(array $data): void
        {
            $this->metadata = $data;
        }
    };
}

/**
 * Run a Serene report end-to-end through RateLimitedErrorReporter backed by a
 * real BugsnagReporter, capturing the report spy the Bugsnag callback runs against.
 */
function captureGroupingHashThroughSerene(Throwable $exception, ?string $key = null): object
{
    $spy = makeGroupingHashSpy();

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->withArgs(function ($ex, $callback) use ($spy) {
            $callback($spy);

            return true;
        });

    $reporter = new RateLimitedErrorReporter(new BugsnagReporter());
    $reporter->report($exception, [], $key);

    return $spy;
}

test('R4.1 explicit key drives the grouping hash', function () {
    $spy = captureGroupingHashThroughSerene(new RuntimeException('boom'), 'my-explicit-key');

    expect($spy->groupingHash)->toBe('my-explicit-key');
});

test('R4.2 GroupableException getErrorGroup drives the grouping hash', function () {
    $exception = new class('boom') extends Exception implements GroupableException {
        public function getErrorGroup(): string
        {
            return 'my-group';
        }
    };

    $spy = captureGroupingHashThroughSerene($exception);

    expect($spy->groupingHash)->toBe('my-group');
});

test('R4.3 auto-generated key drives the grouping hash', function () {
    $exception = new RuntimeException('something failed');

    $spy = captureGroupingHashThroughSerene($exception);

    expect($spy->groupingHash)->toBe(KeyGenerator::fromException($exception));
});

test('R4.4 config off: grouping hash is never set', function () {
    config()->set('serene.group_by_key', false);

    $spy = makeGroupingHashSpy();

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->withArgs(function ($ex, $callback) use ($spy) {
            $callback($spy);

            return true;
        });

    $reporter = new BugsnagReporter();
    $reporter->report(new RuntimeException('boom'), ['key' => 'my-explicit-key']);

    expect($spy->setGroupingHashCalls)->toBe(0)
        ->and($spy->groupingHash)->toBeNull();
});

test('R4.5 no key in context: grouping hash is not set (defensive path)', function () {
    $spy = makeGroupingHashSpy();

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->withArgs(function ($ex, $callback) use ($spy) {
            $callback($spy);

            return true;
        });

    $reporter = new BugsnagReporter();
    $reporter->report(new RuntimeException('boom'), ['custom' => 'value']);

    expect($spy->setGroupingHashCalls)->toBe(0)
        ->and($spy->groupingHash)->toBeNull();
});

test('empty string key in context does not set a grouping hash', function () {
    $spy = makeGroupingHashSpy();

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->withArgs(function ($ex, $callback) use ($spy) {
            $callback($spy);

            return true;
        });

    $reporter = new BugsnagReporter();
    $reporter->report(new RuntimeException('boom'), ['key' => '']);

    expect($spy->setGroupingHashCalls)->toBe(0);
});
