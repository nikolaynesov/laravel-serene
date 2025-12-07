<?php

namespace Nikolaynesov\LaravelSerene\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Nikolaynesov\LaravelSerene\Contracts\ErrorReporter;
use Nikolaynesov\LaravelSerene\Contracts\GroupableException;
use Nikolaynesov\LaravelSerene\Support\KeyGenerator;
use Throwable;

class RateLimitedErrorReporter
{

    public function __construct(
        protected ErrorReporter $provider,
        protected int $cooldownMinutes = 30,
        protected bool $debug = false,
        protected int $maxTrackedUsers = 1000,
        protected int $maxTrackedErrors = 1000
    ) {}

    public function report(Throwable $exception, array $context = [], ?string $key = null): void
    {
        $key = $this->resolveErrorKey($exception, $key);
        $cacheKeys = $this->buildCacheKeys($key);

        // Check if tracking limit reached
        if ($this->shouldBypassThrottlingDueToLimit($cacheKeys['throttle'])) {
            $this->reportImmediatelyAtLimit($exception, $context, $key);
            return;
        }

        // Track affected user if provided
        $this->trackAffectedUser($context, $cacheKeys['users']);

        // Update occurrence statistics
        $stats = $this->incrementOccurrenceStats($cacheKeys['stats']);

        // Handle throttling
        if ($this->isErrorThrottled($cacheKeys['throttle'])) {
            $this->handleThrottledError($key, $stats, $cacheKeys['stats']);
            return;
        }

        // Report error with full context
        $this->reportErrorWithContext($exception, $context, $key, $stats, $cacheKeys);
    }

    /**
     * Resolve the error key using hierarchy: explicit > exception-defined > auto-generated
     */
    protected function resolveErrorKey(Throwable $exception, ?string $key): string
    {
        if ($key !== null) {
            return $key;
        }

        if ($exception instanceof GroupableException) {
            return $exception->getErrorGroup();
        }

        return KeyGenerator::fromException($exception);
    }

    /**
     * Build cache key names for this error
     */
    protected function buildCacheKeys(string $key): array
    {
        $cacheKey = "error-throttler:{$key}";

        return [
            'throttle' => $cacheKey,
            'users' => "{$cacheKey}:users",
            'stats' => "{$cacheKey}:stats",
            'global' => 'serene:global:tracked_errors',
        ];
    }

    /**
     * Check if we should bypass throttling due to tracking limit
     */
    protected function shouldBypassThrottlingDueToLimit(string $throttleKey): bool
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
    protected function getCleanedTrackedErrors(): array
    {
        $trackedErrors = Cache::get('serene:global:tracked_errors', []);
        $now = now()->timestamp;

        return array_filter($trackedErrors, fn($expiry) => $expiry > $now);
    }

    /**
     * Report error immediately when tracking limit is reached
     */
    protected function reportImmediatelyAtLimit(Throwable $exception, array $context, string $key): void
    {
        $context['reported_at'] = now()->toDateTimeString();
        $context['key'] = $key;
        $context['tracking_limit_reached'] = true;

        $this->provider->report($exception, $context);

        if ($this->debug) {
            $trackedErrors = $this->getCleanedTrackedErrors();
            Log::warning("[Serene] {$key} reported immediately (tracking limit reached)", [
                'current_tracked_errors' => count($trackedErrors),
                'max_tracked_errors' => $this->maxTrackedErrors,
            ]);
        }
    }

    /**
     * Track affected user ID if provided in context
     */
    protected function trackAffectedUser(array $context, string $usersKey): void
    {
        $userId = $context['user_id'] ?? null;

        if (!$userId) {
            return;
        }

        $affectedUsers = Cache::get($usersKey, []);

        if ($this->shouldTrackUser($userId, $affectedUsers)) {
            $affectedUsers[] = $userId;
            Cache::put($usersKey, $affectedUsers, now()->addMinutes($this->cooldownMinutes));
        }
    }

    /**
     * Check if user should be tracked (not duplicate and under cap)
     */
    protected function shouldTrackUser(mixed $userId, array $affectedUsers): bool
    {
        return !in_array($userId, $affectedUsers)
            && count($affectedUsers) < $this->maxTrackedUsers;
    }

    /**
     * Increment occurrence statistics
     */
    protected function incrementOccurrenceStats(string $statsKey): array
    {
        $stats = Cache::get($statsKey, ['occurrences' => 0, 'throttled' => 0]);
        $stats['occurrences']++;

        return $stats;
    }

    /**
     * Check if error is currently being throttled
     */
    protected function isErrorThrottled(string $throttleKey): bool
    {
        return Cache::has($throttleKey);
    }

    /**
     * Handle a throttled error (increment counters and log)
     */
    protected function handleThrottledError(string $key, array $stats, string $statsKey): void
    {
        $stats['throttled']++;
        Cache::put($statsKey, $stats, now()->addMinutes($this->cooldownMinutes));

        if ($this->debug) {
            Log::debug("[Serene] {$key} throttled", [
                'occurrences' => $stats['occurrences'],
                'throttled' => $stats['throttled'],
            ]);
        }
    }

    /**
     * Report error with full context and metadata
     */
    protected function reportErrorWithContext(
        Throwable $exception,
        array $context,
        string $key,
        array $stats,
        array $cacheKeys
    ): void {
        $affectedUsers = Cache::get($cacheKeys['users'], []);

        $context = $this->enrichContextWithMetadata($context, $key, $stats, $affectedUsers);

        $this->provider->report($exception, $context);

        $this->logReportedError($key, $stats, $affectedUsers);
        $this->activateThrottling($key, $cacheKeys);
        $this->cleanupAfterReport($cacheKeys);
    }

    /**
     * Enrich context with tracking metadata
     */
    protected function enrichContextWithMetadata(
        array $context,
        string $key,
        array $stats,
        array $affectedUsers
    ): array {
        $context['affected_users'] = $affectedUsers;
        $context['affected_user_count'] = count($affectedUsers);
        $context['user_tracking_capped'] = count($affectedUsers) >= $this->maxTrackedUsers;
        $context['reported_at'] = now()->toDateTimeString();
        $context['key'] = $key;
        $context['occurrences'] = $stats['occurrences'];
        $context['throttled'] = $stats['throttled'];

        return $context;
    }

    /**
     * Log reported error if debug mode is enabled
     */
    protected function logReportedError(string $key, array $stats, array $affectedUsers): void
    {
        if (!$this->debug) {
            return;
        }

        Log::info("[Serene] {$key} reported", [
            'affected_users' => $affectedUsers,
            'count' => count($affectedUsers),
            'occurrences' => $stats['occurrences'],
            'throttled' => $stats['throttled'],
        ]);
    }

    /**
     * Activate throttling for this error
     */
    protected function activateThrottling(string $key, array $cacheKeys): void
    {
        // Set throttle marker
        Cache::put($cacheKeys['throttle'], true, now()->addMinutes($this->cooldownMinutes));

        // Track globally with expiry timestamp
        $trackedErrors = Cache::get($cacheKeys['global'], []);
        $trackedErrors[$key] = now()->addMinutes($this->cooldownMinutes)->timestamp;
        Cache::put($cacheKeys['global'], $trackedErrors, now()->addMinutes($this->cooldownMinutes + 10));
    }

    /**
     * Clean up temporary tracking data after report
     */
    protected function cleanupAfterReport(array $cacheKeys): void
    {
        Cache::forget($cacheKeys['users']);
        Cache::forget($cacheKeys['stats']);
    }

}
