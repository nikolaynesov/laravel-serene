<?php

return [
    /*
     * The reporter provider class implementing ErrorReporter.
     */
    'provider' => \Nikolaynesov\LaravelSerene\Providers\BugsnagReporter::class,

    /*
     * Cooldown period in minutes (default: 30 minutes).
     * Environment: SERENE_REPORTER_COOLDOWN
     */
    'cooldown' => env('SERENE_REPORTER_COOLDOWN', 30),

    /*
     * Enable debug logging for monitoring throttling behavior.
     * When enabled, logs will be written for both reported and throttled errors.
     * Environment: SERENE_REPORTER_DEBUG
     * Default: false
     */
    'debug' => env('SERENE_REPORTER_DEBUG', false),

    /*
     * Maximum number of user IDs to track per error.
     * Prevents cache memory issues during widespread errors.
     * When cap is reached, affected_user_count reflects the cap,
     * and user_tracking_capped flag is set to true.
     * Environment: SERENE_REPORTER_MAX_TRACKED_USERS
     * Default: 1000
     */
    'max_tracked_users' => env('SERENE_REPORTER_MAX_TRACKED_USERS', 1000),
];