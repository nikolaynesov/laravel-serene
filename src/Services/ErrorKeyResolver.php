<?php

namespace Nikolaynesov\LaravelSerene\Services;

use Nikolaynesov\LaravelSerene\Contracts\GroupableException;
use Nikolaynesov\LaravelSerene\Support\KeyGenerator;
use Throwable;

/**
 * Resolves error keys using hierarchy: explicit > exception-defined > auto-generated
 */
class ErrorKeyResolver
{
    /**
     * Resolve error key for the given exception
     */
    public function resolve(Throwable $exception, ?string $explicitKey = null): string
    {
        // Explicit key has highest priority
        if ($explicitKey !== null) {
            return $explicitKey;
        }

        // Exception-defined grouping via interface.
        // An empty group means "no grouping specified" — fall back to
        // auto-generation rather than throwing. This resolver runs inside the
        // application's exception-handling path, so it must degrade gracefully
        // instead of raising a secondary exception.
        if ($exception instanceof GroupableException) {
            $group = $exception->getErrorGroup();

            if (trim($group) !== '') {
                return $group;
            }
        }

        // Auto-generate from exception class and message
        return KeyGenerator::fromException($exception);
    }

    /**
     * Build all cache key names for an error key
     */
    public function buildCacheKeys(string $errorKey): array
    {
        $baseKey = "error-throttler:{$errorKey}";

        return [
            'throttle' => $baseKey,
            'users' => "{$baseKey}:users",
            'stats' => "{$baseKey}:stats",
            'global' => 'serene:global:tracked_errors',
        ];
    }
}
