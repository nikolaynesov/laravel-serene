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
            \Mockery::subset([
                'exception' => $exception,
                'context' => \Mockery::type('array'),
            ])
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
            \Mockery::subset([
                'exception' => $exception,
                'context' => \Mockery::subset([
                    'user_id' => 123,
                    'custom' => 'data',
                ]),
            ])
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
            \Mockery::subset([
                'exception' => $exception,
                'context' => \Mockery::subset([
                    'key' => $customKey,
                ]),
            ])
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
            \Mockery::subset([
                'exception' => $exception,
                'context' => \Mockery::subset([
                    'user_id' => 456,
                    'order_id' => 789,
                    'key' => $key,
                    'affected_users' => [456],
                    'affected_user_count' => 1,
                    'occurrences' => 1,
                    'throttled' => 0,
                ]),
            ])
        );

    Serene::report($exception, $context, $key);
});
