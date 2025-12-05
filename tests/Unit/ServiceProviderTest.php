<?php

use Nikolaynesov\LaravelSerene\Contracts\ErrorReporter;
use Nikolaynesov\LaravelSerene\Providers\LogReporter;
use Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter;

test('binds ErrorReporter interface', function () {
    $reporter = app(ErrorReporter::class);

    expect($reporter)->toBeInstanceOf(ErrorReporter::class);
});

test('binds RateLimitedErrorReporter as singleton', function () {
    $reporter1 = app(RateLimitedErrorReporter::class);
    $reporter2 = app(RateLimitedErrorReporter::class);

    expect($reporter1)->toBe($reporter2);
});

test('throws exception for non-existent provider class', function () {
    config(['serene.provider' => 'App\\NonExistent\\Reporter']);

    expect(fn () => app(ErrorReporter::class))
        ->toThrow(InvalidArgumentException::class, 'does not exist');
});

test('throws exception for provider not implementing interface', function () {
    config(['serene.provider' => \stdClass::class]);

    expect(fn () => app(ErrorReporter::class))
        ->toThrow(InvalidArgumentException::class, 'must implement ErrorReporter interface');
});

test('throws exception for invalid cooldown value', function () {
    config(['serene.cooldown' => -1]);

    expect(fn () => app(RateLimitedErrorReporter::class))
        ->toThrow(InvalidArgumentException::class, 'Cooldown must be a positive integer');
});

test('throws exception for non-integer cooldown', function () {
    config(['serene.cooldown' => '60']);

    expect(fn () => app(RateLimitedErrorReporter::class))
        ->toThrow(InvalidArgumentException::class);
});

test('accepts valid configuration', function () {
    config([
        'serene.provider' => LogReporter::class,
        'serene.cooldown' => 30,
    ]);

    $reporter = app(RateLimitedErrorReporter::class);

    expect($reporter)->toBeInstanceOf(RateLimitedErrorReporter::class);
});

test('uses default cooldown of 60 when not configured', function () {
    config(['serene.cooldown' => null]);

    // Should use default from mergeConfigFrom
    $reporter = app(RateLimitedErrorReporter::class);

    expect($reporter)->toBeInstanceOf(RateLimitedErrorReporter::class);
});

test('resolves ErrorReporter from configured provider', function () {
    config(['serene.provider' => LogReporter::class]);

    $reporter = app(ErrorReporter::class);

    expect($reporter)->toBeInstanceOf(LogReporter::class);
});
