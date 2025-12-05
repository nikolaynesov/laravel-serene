<?php

use Nikolaynesov\LaravelSerene\Support\KeyGenerator;

test('generates consistent keys for same exception', function () {
    $exception = new RuntimeException('Test error');

    $key1 = KeyGenerator::fromException($exception);
    $key2 = KeyGenerator::fromException($exception);

    expect($key1)->toBe($key2);
});

test('generates different keys for different exception messages', function () {
    $exception1 = new RuntimeException('Error one');
    $exception2 = new RuntimeException('Error two');

    $key1 = KeyGenerator::fromException($exception1);
    $key2 = KeyGenerator::fromException($exception2);

    expect($key1)->not->toBe($key2);
});

test('generates different keys for different exception classes', function () {
    $exception1 = new RuntimeException('Same message');
    $exception2 = new InvalidArgumentException('Same message');

    $key1 = KeyGenerator::fromException($exception1);
    $key2 = KeyGenerator::fromException($exception2);

    expect($key1)->not->toBe($key2);
});

test('uses class basename not full namespace', function () {
    $exception = new RuntimeException('Test');

    $key = KeyGenerator::fromException($exception);

    expect($key)->toContain('runtimeexception')
        ->and($key)->not->toContain('\\');
});

test('key format matches expected pattern', function () {
    $exception = new RuntimeException('Test error');

    $key = KeyGenerator::fromException($exception);

    expect($key)->toMatch('/^auto:runtimeexception:[a-f0-9]{32}$/');
});

test('generates lowercase keys', function () {
    $exception = new RuntimeException('Test Error');

    $key = KeyGenerator::fromException($exception);

    expect($key)->toBe(strtolower($key));
});

test('handles exceptions with empty messages', function () {
    $exception = new RuntimeException('');

    $key = KeyGenerator::fromException($exception);

    expect($key)->toMatch('/^auto:runtimeexception:[a-f0-9]{32}$/');
});

test('generates same key for exceptions with same message but different instances', function () {
    $exception1 = new RuntimeException('Duplicate error');
    $exception2 = new RuntimeException('Duplicate error');

    $key1 = KeyGenerator::fromException($exception1);
    $key2 = KeyGenerator::fromException($exception2);

    expect($key1)->toBe($key2);
});
