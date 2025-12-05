<?php

namespace Nikolaynesov\LaravelSerene\Facades;

use Illuminate\Support\Facades\Facade;
use Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter;

/**
 * @method static void report(\Throwable $exception, array $context = [], ?string $key = null)
 *
 * @see \Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter
 */
class Serene extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RateLimitedErrorReporter::class;
    }
}
