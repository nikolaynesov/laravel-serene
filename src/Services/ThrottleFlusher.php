<?php

namespace Nikolaynesov\LaravelSerene\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Nikolaynesov\LaravelSerene\Contracts\ErrorReporter;

/**
 * Handles flushing of throttled errors
 */
class ThrottleFlusher
{
    public function __construct(
        protected ErrorReporter $provider,
        protected ErrorKeyResolver $keyResolver,
        protected ThrottleManager $throttleManager,
        protected UserTracker $userTracker,
        protected ErrorStatistics $statistics
    ) {}

    /**
     * Flush all throttled errors and report their accumulated statistics
     */
    public function flushAll(bool $dryRun = false): array
    {
        $trackedErrors = $this->throttleManager->getCleanedTrackedErrors();
        $flushed = [];

        foreach ($trackedErrors as $key => $expiry) {
            $cacheKeys = $this->keyResolver->buildCacheKeys($key);

            $stats = $this->statistics->getStats($cacheKeys['stats']);

            // Only flush if there are throttled occurrences
            // Note: Flush works for both active and expired throttles
            // - Active throttles: manual flush to see accumulated data
            // - Expired throttles: automatic flush to catch orphaned data
            if ($stats['throttled'] === 0) {
                continue;
            }

            $flushed[] = [
                'key' => $key,
                'occurrences' => $stats['occurrences'],
                'throttled' => $stats['throttled'],
                'affected_users' => $this->userTracker->getAffectedUsers($cacheKeys['users']),
            ];

            // Actually flush if not dry-run
            if (!$dryRun) {
                $this->flushSingle($key, $cacheKeys, $stats);
            }
        }

        return $flushed;
    }

    /**
     * Flush a single throttled error
     */
    protected function flushSingle(string $key, array $cacheKeys, array $stats): void
    {
        $affectedUsers = $this->userTracker->getAffectedUsers($cacheKeys['users']);

        // Try to extract original exception class from key if auto-generated
        $originalExceptionClass = $this->extractExceptionClassFromKey($key);

        // Create a synthetic exception for reporting
        $exception = new \RuntimeException("Throttled error flush: {$key}");

        // Build context with preserved capped state from stats
        $baseContext = [
            'flushed_by_command' => true,
            'original_error_key' => $key,
            'original_exception_class' => $originalExceptionClass,
            'note' => 'This is a flushed throttled error. Original exceptions occurred during cooldown period but stopped before next report.',
        ];

        $context = $this->statistics->buildEnrichedContext(
            $baseContext,
            $key,
            $stats,
            $affectedUsers,
            $this->userTracker->getMaxTrackedUsers()
        );

        $this->provider->report($exception, $context);

        // Clear throttle and tracking data
        $this->throttleManager->clearThrottle($cacheKeys['throttle']);
        $this->userTracker->clearAffectedUsers($cacheKeys['users']);
        $this->statistics->clearStats($cacheKeys['stats']);

        // Remove from global tracking
        $this->throttleManager->removeFromGlobalTracking($key, $cacheKeys['global']);

        if (config('serene.debug')) {
            Log::info("[Serene] {$key} flushed by command", [
                'occurrences' => $stats['occurrences'],
                'throttled' => $stats['throttled'],
                'affected_users_count' => count($affectedUsers),
            ]);
        }
    }

    /**
     * Extract original exception class from auto-generated key
     * Key format: auto:classname:hash
     */
    protected function extractExceptionClassFromKey(string $key): ?string
    {
        // Check if it's an auto-generated key
        if (!str_starts_with($key, 'auto:')) {
            return null;
        }

        // Parse the key: auto:classname:hash
        $parts = explode(':', $key);
        if (count($parts) >= 2) {
            // Return the class name part (capitalize first letter)
            return ucfirst($parts[1]);
        }

        return null;
    }
}
