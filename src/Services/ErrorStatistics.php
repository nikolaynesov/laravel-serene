<?php

namespace Nikolaynesov\LaravelSerene\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Manages error occurrence and throttle statistics
 */
class ErrorStatistics
{
    public function __construct(
        protected int $cooldownMinutes
    ) {}

    /**
     * Increment occurrence count
     */
    public function incrementOccurrence(string $statsKey): array
    {
        $stats = $this->getStats($statsKey);

        // Record first occurrence time if not set
        if ($stats['first_seen'] === null) {
            $stats['first_seen'] = now()->timestamp;
        }

        $stats['occurrences']++;

        // Use fixed TTL from first occurrence (cooldown + 10 min buffer for flush)
        $expiresAt = \Carbon\Carbon::createFromTimestamp($stats['first_seen'])
            ->addMinutes($this->cooldownMinutes + 10);

        Cache::put($statsKey, $stats, $expiresAt);

        return $stats;
    }

    /**
     * Increment throttle count
     */
    public function incrementThrottle(string $statsKey): void
    {
        $stats = $this->getStats($statsKey);
        $stats['throttled']++;

        // Use fixed TTL from first occurrence (cooldown + 10 min buffer for flush)
        $expiresAt = \Carbon\Carbon::createFromTimestamp($stats['first_seen'])
            ->addMinutes($this->cooldownMinutes + 10);

        Cache::put($statsKey, $stats, $expiresAt);
    }

    /**
     * Get statistics for an error
     */
    public function getStats(string $statsKey): array
    {
        return Cache::get($statsKey, [
            'occurrences' => 0,
            'throttled' => 0,
            'first_seen' => null,
            'user_tracking_was_capped' => false,
        ]);
    }

    /**
     * Mark that user tracking was capped for this error
     */
    public function markUserTrackingCapped(string $statsKey): void
    {
        $stats = $this->getStats($statsKey);
        $stats['user_tracking_was_capped'] = true;

        // Use same TTL logic as other stats updates
        $expiresAt = \Carbon\Carbon::createFromTimestamp($stats['first_seen'])
            ->addMinutes($this->cooldownMinutes + 10);

        Cache::put($statsKey, $stats, $expiresAt);
    }

    /**
     * Clear statistics
     */
    public function clearStats(string $statsKey): void
    {
        Cache::forget($statsKey);
    }

    /**
     * Reset the capped flag and first_seen for a new cycle
     * This starts a fresh TTL window while preserving occurrence counts
     */
    public function resetCappedFlag(string $statsKey): void
    {
        $stats = $this->getStats($statsKey);
        $stats['user_tracking_was_capped'] = false;
        // Reset first_seen so next occurrence starts fresh TTL window
        $stats['first_seen'] = null;

        // If stats exist, save with current TTL, otherwise will be recreated on next occurrence
        if ($stats['occurrences'] > 0 || $stats['throttled'] > 0) {
            // Use a short TTL until next occurrence which will set proper first_seen
            Cache::put($statsKey, $stats, now()->addMinutes(10));
        }
    }

    /**
     * Build enriched context with statistics and user tracking data
     */
    public function buildEnrichedContext(
        array $context,
        string $key,
        array $stats,
        array $affectedUsers,
        int $maxTrackedUsers
    ): array {
        $context['affected_users'] = $affectedUsers;
        $context['affected_user_count'] = count($affectedUsers);
        // Use persisted capped state from stats if available, otherwise calculate from current users
        $context['user_tracking_capped'] = $stats['user_tracking_was_capped'] ?? (count($affectedUsers) >= $maxTrackedUsers);
        $context['reported_at'] = now()->toDateTimeString();
        $context['key'] = $key;
        $context['occurrences'] = $stats['occurrences'];
        $context['throttled'] = $stats['throttled'];

        return $context;
    }
}
