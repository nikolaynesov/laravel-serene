<?php

use Illuminate\Support\Facades\Log;
use Nikolaynesov\LaravelSerene\Providers\LogReporter;

test('logs exception to Laravel logs', function () {
    $exception = new RuntimeException('Test error message');

    Log::shouldReceive('error')
        ->once()
        ->with(
            'Test error message',
            \Mockery::subset([
                'exception' => $exception,
                'context' => [],
            ])
        );

    $reporter = new LogReporter();
    $reporter->report($exception);
});

test('includes exception in context', function () {
    $exception = new RuntimeException('Test');

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $ctx) {
            return $message === 'Test'
                && $ctx['exception'] instanceof RuntimeException
                && $ctx['context'] === [];
        });

    $reporter = new LogReporter();
    $reporter->report($exception);
});

test('includes custom context', function () {
    $exception = new RuntimeException('Test');
    $context = [
        'user_id' => 123,
        'custom' => 'value',
    ];

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $ctx) {
            return $message === 'Test'
                && $ctx['exception'] instanceof RuntimeException
                && $ctx['context'] === [
                    'user_id' => 123,
                    'custom' => 'value',
                ];
        });

    $reporter = new LogReporter();
    $reporter->report($exception, $context);
});

test('uses error log level', function () {
    $exception = new RuntimeException('Test');

    Log::shouldReceive('error')
        ->once()
        ->with(
            'Test',
            \Mockery::type('array')
        );

    // Verify it's not using warning, info, debug, etc.
    Log::shouldNotReceive('warning');
    Log::shouldNotReceive('info');
    Log::shouldNotReceive('debug');

    $reporter = new LogReporter();
    $reporter->report($exception);
});

test('handles empty context', function () {
    $exception = new RuntimeException('Test');

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $ctx) {
            return $message === 'Test'
                && $ctx['exception'] instanceof RuntimeException
                && $ctx['context'] === [];
        });

    $reporter = new LogReporter();
    $reporter->report($exception);
});

test('handles different exception types', function () {
    $exception1 = new RuntimeException('Runtime error');
    $exception2 = new InvalidArgumentException('Invalid argument');

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $ctx) {
            return $message === 'Runtime error'
                && $ctx['exception'] instanceof RuntimeException;
        });

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $ctx) {
            return $message === 'Invalid argument'
                && $ctx['exception'] instanceof InvalidArgumentException;
        });

    $reporter = new LogReporter();

    $reporter->report($exception1);
    $reporter->report($exception2);
});

test('context array structure is correct', function () {
    $exception = new RuntimeException('Test');
    $context = [
        'user_id' => 123,
        'order_id' => 456,
        'meta' => ['key' => 'value'],
    ];

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $ctx) {
            return $message === 'Test'
                && $ctx['exception'] instanceof RuntimeException
                && $ctx['context'] === [
                    'user_id' => 123,
                    'order_id' => 456,
                    'meta' => ['key' => 'value'],
                ];
        });

    $reporter = new LogReporter();
    $reporter->report($exception, $context);
});

test('passes exact exception instance', function () {
    $exception = new RuntimeException('Exact test');

    Log::shouldReceive('error')
        ->once()
        ->with(
            'Exact test',
            [
                'exception' => $exception,
                'context' => [],
            ]
        );

    $reporter = new LogReporter();
    $reporter->report($exception);
});
