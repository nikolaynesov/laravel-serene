<?php

use Illuminate\Support\Facades\Log;
use Nikolaynesov\LaravelSerene\Facades\Serene;

test('facade resolves correctly', function () {
    expect(Serene::getFacadeRoot())
        ->toBeInstanceOf(\Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter::class);
});

test('facade report method works', function () {
    $exception = new RuntimeException('Test error');

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $payload) use ($exception) {
            return $message === 'Test error'
                && $payload['exception'] === $exception
                && is_array($payload['context']);
        });

    Serene::report($exception);
});

test('facade is registered as alias', function () {
    expect(class_exists('Serene'))->toBeTrue();
});

test('can use facade with context', function () {
    $exception = new RuntimeException('Test');
    $context = [
        'user_id' => 123,
        'custom' => 'data',
    ];

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $payload) use ($exception) {
            return $message === 'Test'
                && $payload['exception'] === $exception
                && $payload['context']['user_id'] === 123
                && $payload['context']['custom'] === 'data';
        });

    Serene::report($exception, $context);
});

test('can use facade with custom key', function () {
    $exception = new RuntimeException('Test');
    $customKey = 'custom:key';

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $payload) use ($exception, $customKey) {
            return $message === 'Test'
                && $payload['exception'] === $exception
                && $payload['context']['key'] === $customKey;
        });

    Serene::report($exception, [], $customKey);
});

test('facade passes all parameters correctly', function () {
    $exception = new RuntimeException('Complete test');
    $context = [
        'user_id' => 456,
        'order_id' => 789,
    ];
    $key = 'test:key:123';

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $payload) use ($exception, $key) {
            $context = $payload['context'];

            return $message === 'Complete test'
                && $payload['exception'] === $exception
                && $context['user_id'] === 456
                && $context['order_id'] === 789
                && $context['key'] === $key
                && $context['affected_users'] === [456]
                && $context['affected_user_count'] === 1
                && $context['occurrences'] === 1
                && $context['throttled'] === 0;
        });

    Serene::report($exception, $context, $key);
});
