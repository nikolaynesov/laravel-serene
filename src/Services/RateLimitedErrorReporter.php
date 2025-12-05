<?php

namespace Nikolaynesov\LaravelSerene\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Nikolaynesov\LaravelSerene\Contracts\ErrorReporter;
use Nikolaynesov\LaravelSerene\Support\KeyGenerator;
use Throwable;

class RateLimitedErrorReporter
{

    public function __construct(
        protected ErrorReporter $provider,
        protected int $cooldownMinutes = 30,
        protected bool $debug = false,
        protected int $maxTrackedUsers = 1000
    ) {}

    public function report(Throwable $exception, array $context = [], ?string $key = null): void
    {
        $key ??= KeyGenerator::fromException($exception);
        $cacheKey = "error-throttler:{$key}";
        $usersKey = "{$cacheKey}:users";
        $statsKey = "{$cacheKey}:stats";

        $userId = $context['user_id'] ?? null;
        if ($userId) {
            $affectedUsers = Cache::get($usersKey, []);
            if (!in_array($userId, $affectedUsers) && count($affectedUsers) < $this->maxTrackedUsers) {
                $affectedUsers[] = $userId;
                Cache::put($usersKey, $affectedUsers, now()->addMinutes($this->cooldownMinutes));
            }
        }

        // Increment occurrence count
        $stats = Cache::get($statsKey, ['occurrences' => 0, 'throttled' => 0]);
        $stats['occurrences']++;

        if (Cache::has($cacheKey)) {
            // Error is throttled - increment throttle counter
            $stats['throttled']++;
            Cache::put($statsKey, $stats, now()->addMinutes($this->cooldownMinutes));

            if ($this->debug) {
                Log::debug("[Serene] {$key} throttled", [
                    'occurrences' => $stats['occurrences'],
                    'throttled' => $stats['throttled'],
                ]);
            }

            return;
        }

        $affectedUsers = Cache::get($usersKey, []);

        $context['affected_users'] = $affectedUsers;
        $context['affected_user_count'] = count($affectedUsers);
        $context['user_tracking_capped'] = count($affectedUsers) >= $this->maxTrackedUsers;
        $context['reported_at'] = now()->toDateTimeString();
        $context['key'] = $key;
        $context['occurrences'] = $stats['occurrences'];
        $context['throttled'] = $stats['throttled'];

        $this->provider->report($exception, $context);

        if ($this->debug) {
            Log::info("[Serene] {$key} reported", [
                'affected_users' => $affectedUsers,
                'count' => count($affectedUsers),
                'occurrences' => $stats['occurrences'],
                'throttled' => $stats['throttled'],
            ]);
        }

        Cache::put($cacheKey, true, now()->addMinutes($this->cooldownMinutes));
        Cache::forget($usersKey);
        Cache::forget($statsKey);
    }

}