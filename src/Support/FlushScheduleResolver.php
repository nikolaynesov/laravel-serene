<?php

namespace Nikolaynesov\LaravelSerene\Support;

use Illuminate\Console\Scheduling\Event;

/**
 * Resolves the optimal schedule frequency for flush command
 * based on calculated flush interval
 */
class FlushScheduleResolver
{
    /**
     * Apply appropriate schedule frequency to the event
     */
    public function apply(Event $event, int $intervalMinutes): void
    {
        $cronExpression = $this->buildCronExpression($intervalMinutes);
        $event->cron($cronExpression);
    }

    /**
     * Build cron expression for any interval in minutes
     */
    protected function buildCronExpression(int $intervalMinutes): string
    {
        // For very long intervals (60+ minutes), use hourly cron
        if ($intervalMinutes >= 60) {
            return $this->buildHourlyCron($intervalMinutes);
        }

        // For intervals that divide 60 evenly, use simple */N syntax
        if (60 % $intervalMinutes === 0) {
            return "*/{$intervalMinutes} * * * *";
        }

        // For intervals that don't divide 60 evenly, generate minute list
        return $this->buildMinuteListCron($intervalMinutes);
    }

    /**
     * Build cron for intervals >= 60 minutes
     */
    protected function buildHourlyCron(int $intervalMinutes): string
    {
        $hours = (int) floor($intervalMinutes / 60);

        // If it's exactly N hours, use hour syntax
        if ($intervalMinutes % 60 === 0 && $hours < 24 && 24 % $hours === 0) {
            return "0 */{$hours} * * *";
        }

        // For very long intervals or odd values, default to hourly
        // (running more frequently is better than less frequently)
        return "0 * * * *"; // Every hour at minute 0
    }

    /**
     * Build cron with explicit minute list for non-divisor intervals
     *
     * Example: 7 minutes → "0,7,14,21,28,35,42,49,56 * * * *"
     */
    protected function buildMinuteListCron(int $intervalMinutes): string
    {
        $minutes = [];

        for ($minute = 0; $minute < 60; $minute += $intervalMinutes) {
            $minutes[] = $minute;
        }

        return implode(',', $minutes) . ' * * * *';
    }

    /**
     * Get human-readable description of the schedule
     * (useful for debugging/logging)
     */
    public function describe(int $intervalMinutes): string
    {
        if ($intervalMinutes >= 60) {
            $hours = round($intervalMinutes / 60, 1);
            return "every {$hours} hour(s)";
        }

        if (60 % $intervalMinutes === 0) {
            return "every {$intervalMinutes} minute(s)";
        }

        $timesPerHour = (int) floor(60 / $intervalMinutes);
        return "approximately {$timesPerHour}x per hour (every ~{$intervalMinutes} minutes)";
    }
}
