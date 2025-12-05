<?php

use Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter;

test('service provider passes max_tracked_users from config', function () {
    config(['serene.max_tracked_users' => 500]);

    $reporter = app(RateLimitedErrorReporter::class);

    expect($reporter)->toBeInstanceOf(RateLimitedErrorReporter::class);
});

test('service provider defaults max_tracked_users to 1000', function () {
    config(['serene.max_tracked_users' => null]);

    $reporter = app(RateLimitedErrorReporter::class);

    expect($reporter)->toBeInstanceOf(RateLimitedErrorReporter::class);
});

test('service provider validates max_tracked_users is integer', function () {
    config(['serene.max_tracked_users' => '1000']);

    expect(fn () => app(RateLimitedErrorReporter::class))
        ->toThrow(InvalidArgumentException::class, 'Max tracked users must be a positive integer');
});

test('service provider validates max_tracked_users is positive', function () {
    config(['serene.max_tracked_users' => 0]);

    expect(fn () => app(RateLimitedErrorReporter::class))
        ->toThrow(InvalidArgumentException::class, 'Max tracked users must be a positive integer');
});

test('service provider rejects negative max_tracked_users', function () {
    config(['serene.max_tracked_users' => -100]);

    expect(fn () => app(RateLimitedErrorReporter::class))
        ->toThrow(InvalidArgumentException::class);
});

test('service provider accepts small max_tracked_users values', function () {
    config(['serene.max_tracked_users' => 10]);

    $reporter = app(RateLimitedErrorReporter::class);

    expect($reporter)->toBeInstanceOf(RateLimitedErrorReporter::class);
});

test('service provider accepts large max_tracked_users values', function () {
    config(['serene.max_tracked_users' => 100000]);

    $reporter = app(RateLimitedErrorReporter::class);

    expect($reporter)->toBeInstanceOf(RateLimitedErrorReporter::class);
});

test('service provider rejects boolean for max_tracked_users', function () {
    config(['serene.max_tracked_users' => true]);

    expect(fn () => app(RateLimitedErrorReporter::class))
        ->toThrow(InvalidArgumentException::class);
});

test('service provider rejects array for max_tracked_users', function () {
    config(['serene.max_tracked_users' => []]);

    expect(fn () => app(RateLimitedErrorReporter::class))
        ->toThrow(InvalidArgumentException::class);
});
