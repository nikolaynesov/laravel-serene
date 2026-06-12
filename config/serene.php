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
    'cooldown' => (int) env('SERENE_REPORTER_COOLDOWN', 30),

    /*
     * Enable debug logging for monitoring throttling behavior.
     * When enabled, logs will be written for both reported and throttled errors.
     * Environment: SERENE_REPORTER_DEBUG
     * Default: false
     */
    'debug' => (bool) env('SERENE_REPORTER_DEBUG', false),

    /*
     * Maximum number of user IDs to track per error.
     * Prevents cache memory issues during widespread errors.
     * When cap is reached, affected_user_count reflects the cap,
     * and user_tracking_capped flag is set to true.
     * Environment: SERENE_REPORTER_MAX_TRACKED_USERS
     * Default: 1000
     */
    'max_tracked_users' => (int) env('SERENE_REPORTER_MAX_TRACKED_USERS', 1000),

    /*
     * Maximum number of unique errors to track simultaneously.
     * Prevents catastrophic cache memory usage if millions of unique errors occur.
     * When limit is reached, new errors are reported immediately without throttling.
     * Environment: SERENE_REPORTER_MAX_TRACKED_ERRORS
     * Default: 1000
     */
    'max_tracked_errors' => (int) env('SERENE_REPORTER_MAX_TRACKED_ERRORS', 1000),

    /*
     * Use the resolved group key as Bugsnag's grouping hash.
     *
     * When true (default), an explicit key passed to Serene::report() or an
     * exception's getErrorGroup() value drives how Bugsnag groups the error,
     * so all occurrences of a group collapse into a single Bugsnag error
     * regardless of stacktrace. When false, Bugsnag falls back to its default
     * stacktrace-based grouping and the key only affects Serene's throttling.
     *
     * Note: enabling this re-groups errors that were previously reported
     * through Serene. After upgrading you may want to mark superseded Bugsnag
     * errors as fixed.
     *
     * Environment: SERENE_REPORTER_SET_GROUPING_HASH
     * Default: true
     */
    'set_grouping_hash' => (bool) env('SERENE_REPORTER_SET_GROUPING_HASH', true),
];