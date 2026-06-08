<?php

namespace Nikolaynesov\LaravelSerene\Services;

use Illuminate\Support\Facades\Log;
use Nikolaynesov\LaravelSerene\Contracts\ErrorReporter;
use Throwable;

class RateLimitedErrorReporter
{
    public function __construct(
        protected ErrorReporter $provider,
        protected ErrorKeyResolver $keyResolver,
        protected ThrottleManager $throttleManager,
        protected UserTracker $userTracker,
        protected ErrorStatistics $statistics,
        protected ThrottleFlusher $flusher
    ) {}

    public function report(Throwable $exception, array $context = [], ?string $key = null): void
    {
        $key = $this->keyResolver->resolve($exception, $key);
        $cacheKeys = $this->keyResolver->buildCacheKeys($key);

        // Check if tracking limit reached
        if ($this->throttleManager->shouldBypassDueToLimit($cacheKeys['throttle'])) {
            $this->reportImmediatelyAtLimit($exception, $context, $key);
            return;
        }

        // Update occurrence statistics
        $stats = $this->statistics->incrementOccurrence($cacheKeys['stats']);

        // Handle throttling
        if ($this->throttleManager->isThrottled($cacheKeys['throttle'])) {
            // During throttle: accumulate users
            $this->userTracker->trackUser($context, $cacheKeys['users'], function() use ($cacheKeys) {
                // Mark in stats that user tracking was capped
                $this->statistics->markUserTrackingCapped($cacheKeys['stats']);
            });
            $this->handleThrottledError($key, $stats, $cacheKeys['stats']);
            return;
        }

        // Not throttled - report error
        // If there's a new user to track, start fresh cycle; otherwise report accumulated users
        $hasNewUser = isset($context['user_id']) && $context['user_id'] !== null && $context['user_id'] !== '';

        if ($hasNewUser) {
            // New user present: start fresh reporting cycle
            // Clear accumulated users from previous throttle period
            $this->userTracker->clearAffectedUsers($cacheKeys['users']);

            // Track the new user for this reporting cycle
            $this->userTracker->trackUser($context, $cacheKeys['users'], function() use ($cacheKeys) {
                $this->statistics->markUserTrackingCapped($cacheKeys['stats']);
            });
        }
        // else: no new user, report with accumulated users from throttle period

        // Report error with full context
        $this->reportErrorWithContext($exception, $context, $key, $stats, $cacheKeys, $hasNewUser);
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

        if (config('serene.debug')) {
            $trackedErrors = $this->throttleManager->getCleanedTrackedErrors();
            Log::warning("[Serene] {$key} reported immediately (tracking limit reached)", [
                'current_tracked_errors' => count($trackedErrors),
            ]);
        }
    }


    /**
     * Handle a throttled error (increment counters and log)
     */
    protected function handleThrottledError(string $key, array $stats, string $statsKey): void
    {
        $this->statistics->incrementThrottle($statsKey);

        if (config('serene.debug')) {
            Log::debug("[Serene] {$key} throttled", [
                'occurrences' => $stats['occurrences'],
                'throttled' => $stats['throttled'] + 1,
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
        array $cacheKeys,
        bool $hasNewUser
    ): void {
        $affectedUsers = $this->userTracker->getAffectedUsers($cacheKeys['users']);

        $context = $this->statistics->buildEnrichedContext(
            $context,
            $key,
            $stats,
            $affectedUsers,
            $this->userTracker->getMaxTrackedUsers()
        );

        $this->provider->report($exception, $context);

        $this->logReportedError($key, $stats, $affectedUsers);
        $this->throttleManager->activateThrottle($cacheKeys['throttle'], $key, $cacheKeys['global']);
        $this->cleanupAfterReport($cacheKeys, $hasNewUser);
    }

    /**
     * Log reported error if debug mode is enabled
     */
    protected function logReportedError(string $key, array $stats, array $affectedUsers): void
    {
        if (!config('serene.debug')) {
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
     * Clean up temporary tracking data after report
     *
     * Note: With fixed TTL implementation:
     * - Stats persist until natural TTL expiration (first_seen + cooldown + 10)
     * - Users cleared only when reporting without new user (end of throttle cycle)
     * - Users kept when reporting with new user (accumulate during throttle period)
     * - Capped flag reset when users cleared to start fresh for next cycle
     */
    protected function cleanupAfterReport(array $cacheKeys, bool $hasNewUser): void
    {
        // Clear users only when reporting without new user (end of throttle cycle)
        // This allows accumulation during the period but resets between cycles
        if (!$hasNewUser) {
            $this->userTracker->clearAffectedUsers($cacheKeys['users']);
            // Reset capped flag for next cycle while preserving other stats
            $this->statistics->resetCappedFlag($cacheKeys['stats']);
        }
        // Stats persist for accumulation across cycles
    }

    /**
     * Flush all throttled errors and report their accumulated statistics
     *
     * This method finds all active throttled errors and reports them with
     * accumulated stats, then clears the throttle markers. Useful for ensuring
     * no throttled data is lost when errors stop occurring.
     *
     * @param bool $dryRun If true, returns what would be flushed without actually flushing
     * @return array Array of flushed error information
     */
    public function flushThrottledErrors(bool $dryRun = false): array
    {
        return $this->flusher->flushAll($dryRun);
    }

}
