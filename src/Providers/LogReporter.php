<?php

namespace Nikolaynesov\LaravelSerene\Providers;

use Illuminate\Support\Facades\Log;
use Nikolaynesov\LaravelSerene\Contracts\ErrorReporter;
use Throwable;

class LogReporter implements ErrorReporter
{
    public function report(Throwable $exception, array $context = []): void
    {
        Log::error($exception->getMessage(), [
            'exception' => $exception,
            'context' => $context,
        ]);
    }
}