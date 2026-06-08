<?php

namespace Nikolaynesov\LaravelSerene\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Manages throttle state and tracking limits
 */
class ThrottleManager
{
    public function __construct(
        protected int $cooldownMinutes,
        protected int $maxTrackedErrors
    ) {}

    /**
     * Check if error is currently being throttled
     */
    public function isThrottled(string $throttleKey): bool
    {
        return Cache::has($throttleKey);
    }

    /**
     * Activate throttling for an error
     */
    public function activateThrottle(string $throttleKey, string $errorKey, string $globalKey): void
    {
        // Set throttle marker
        Cache::put($throttleKey, true, now()->addMinutes($this->cooldownMinutes));

        // Track globally with expiry timestamp
        // Use long fixed TTL (24 hours) to prevent memory leak from constant resets
        $trackedErrors = Cache::get($globalKey, []);
        $trackedErrors[$errorKey] = now()->addMinutes($this->cooldownMinutes)->timestamp;
        Cache::put($globalKey, $trackedErrors, now()->addHours(24));
    }

    /**
     * Clear throttle marker
     */
    public function clearThrottle(string $throttleKey): void
    {
        Cache::forget($throttleKey);
    }

    /**
     * Check if we should bypass throttling due to tracking limit
     */
    public function shouldBypassDueToLimit(string $throttleKey): bool
    {
        $isNewError = !Cache::has($throttleKey);

        if (!$isNewError) {
            return false;
        }

        $trackedErrors = $this->getCleanedTrackedErrors();

        return count($trackedErrors) >= $this->maxTrackedErrors;
    }

    /**
     * Get tracked errors list with expired entries removed
     */
    public function getCleanedTrackedErrors(): array
    {
        $trackedErrors = Cache::get('serene:global:tracked_errors', []);
        $now = now()->timestamp;

        return array_filter($trackedErrors, fn($expiry) => $expiry > $now);
    }

    /**
     * Remove error from global tracking
     */
    public function removeFromGlobalTracking(string $errorKey, string $globalKey): void
    {
        $trackedErrors = Cache::get($globalKey, []);
        unset($trackedErrors[$errorKey]);

        // Use same long fixed TTL (24 hours) - don't reset on every removal
        Cache::put($globalKey, $trackedErrors, now()->addHours(24));
    }
}
