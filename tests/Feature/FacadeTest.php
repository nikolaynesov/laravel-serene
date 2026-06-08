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
        ->with(
            'Test error',
            \Mockery::on(function ($arg) use ($exception) {
                return $arg['exception'] === $exception
                    && is_array($arg['context'])
                    && isset($arg['context']['occurrences'])
                    && isset($arg['context']['throttled'])
                    && isset($arg['context']['affected_users'])
                    && isset($arg['context']['key']);
            })
        );

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
        ->with(
            'Test',
            \Mockery::on(function ($arg) use ($exception) {
                return $arg['exception'] === $exception
                    && is_array($arg['context'])
                    && $arg['context']['user_id'] === 123
                    && $arg['context']['custom'] === 'data'
                    && isset($arg['context']['occurrences'])
                    && isset($arg['context']['affected_users'])
                    && in_array(123, $arg['context']['affected_users']);
            })
        );

    Serene::report($exception, $context);
});

test('can use facade with custom key', function () {
    $exception = new RuntimeException('Test');
    $customKey = 'custom:key';

    Log::shouldReceive('error')
        ->once()
        ->with(
            'Test',
            \Mockery::on(function ($arg) use ($exception, $customKey) {
                return $arg['exception'] === $exception
                    && is_array($arg['context'])
                    && $arg['context']['key'] === $customKey
                    && isset($arg['context']['occurrences'])
                    && isset($arg['context']['throttled']);
            })
        );

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
        ->with(
            'Complete test',
            \Mockery::on(function ($arg) use ($exception, $key) {
                return $arg['exception'] === $exception
                    && is_array($arg['context'])
                    && $arg['context']['user_id'] === 456
                    && $arg['context']['order_id'] === 789
                    && $arg['context']['key'] === $key
                    && $arg['context']['affected_users'] === [456]
                    && $arg['context']['affected_user_count'] === 1
                    && $arg['context']['occurrences'] === 1
                    && $arg['context']['throttled'] === 0;
            })
        );

    Serene::report($exception, $context, $key);
});
