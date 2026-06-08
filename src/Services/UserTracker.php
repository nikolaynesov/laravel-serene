<?php

namespace Nikolaynesov\LaravelSerene\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Tracks affected users for errors during throttle periods
 */
class UserTracker
{
    public function __construct(
        protected int $cooldownMinutes,
        protected int $maxTrackedUsers
    ) {}

    /**
     * Track affected user ID if provided in context
     */
    public function trackUser(array $context, string $usersKey, callable $onCapReached = null): void
    {
        $userId = $context['user_id'] ?? null;

        // Allow 0 and false as valid user IDs, only skip null and empty string
        if ($userId === null || $userId === '') {
            return;
        }

        $affectedUsers = $this->getAffectedUsers($usersKey);

        if ($this->shouldTrackUser($userId, $affectedUsers)) {
            $affectedUsers[] = $userId;

            // Get or create metadata to track first user timestamp
            $metaKey = "{$usersKey}:meta";
            $meta = Cache::get($metaKey, ['first_seen' => now()->timestamp]);

            // Use fixed TTL from first user tracked (cooldown + 10 min buffer for flush)
            $expiresAt = \Carbon\Carbon::createFromTimestamp($meta['first_seen'])
                ->addMinutes($this->cooldownMinutes + 10);

            // Check if we've reached the cap
            if (count($affectedUsers) >= $this->maxTrackedUsers && $onCapReached) {
                $onCapReached();
            }

            Cache::put($usersKey, $affectedUsers, $expiresAt);
            Cache::put($metaKey, $meta, $expiresAt);
        }
    }

    /**
     * Get affected users for an error
     */
    public function getAffectedUsers(string $usersKey): array
    {
        return Cache::get($usersKey, []);
    }

    /**
     * Clear affected users tracking
     */
    public function clearAffectedUsers(string $usersKey): void
    {
        Cache::forget($usersKey);
        Cache::forget("{$usersKey}:meta");
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
     * Check if user tracking is capped
     */
    public function isTrackingCapped(array $affectedUsers): bool
    {
        return count($affectedUsers) >= $this->maxTrackedUsers;
    }

    /**
     * Get the max tracked users limit
     */
    public function getMaxTrackedUsers(): int
    {
        return $this->maxTrackedUsers;
    }
}
