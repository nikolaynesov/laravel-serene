<?php

namespace Nikolaynesov\LaravelSerene\Contracts;

use Throwable;

interface ErrorReporter
{
    /**
     * Report an exception with metadata and context.
     *
     * @param Throwable $exception
     * @param array $context  (e.g. ['user_id' => 123, 'meta' => ['foo' => 'bar']])
     * @return void
     */
    public function report(Throwable $exception, array $context = []): void;
}