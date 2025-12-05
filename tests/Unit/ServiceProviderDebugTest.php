<?php

use Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter;

test('service provider passes debug flag from config', function () {
    config(['serene.debug' => true]);

    $reporter = app(RateLimitedErrorReporter::class);

    expect($reporter)->toBeInstanceOf(RateLimitedErrorReporter::class);
});

test('service provider defaults debug to false', function () {
    config(['serene.debug' => null]);

    $reporter = app(RateLimitedErrorReporter::class);

    expect($reporter)->toBeInstanceOf(RateLimitedErrorReporter::class);
});

test('service provider validates debug is boolean', function () {
    config(['serene.debug' => 'true']);

    expect(fn () => app(RateLimitedErrorReporter::class))
        ->toThrow(InvalidArgumentException::class, 'Debug must be a boolean');
});

test('service provider accepts true for debug', function () {
    config(['serene.debug' => true]);

    $reporter = app(RateLimitedErrorReporter::class);

    expect($reporter)->toBeInstanceOf(RateLimitedErrorReporter::class);
});

test('service provider accepts false for debug', function () {
    config(['serene.debug' => false]);

    $reporter = app(RateLimitedErrorReporter::class);

    expect($reporter)->toBeInstanceOf(RateLimitedErrorReporter::class);
});

test('service provider rejects integer for debug', function () {
    config(['serene.debug' => 1]);

    expect(fn () => app(RateLimitedErrorReporter::class))
        ->toThrow(InvalidArgumentException::class);
});

test('service provider rejects array for debug', function () {
    config(['serene.debug' => []]);

    expect(fn () => app(RateLimitedErrorReporter::class))
        ->toThrow(InvalidArgumentException::class);
});
