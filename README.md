# Laravel Serene

[![Latest Version on Packagist](https://img.shields.io/packagist/v/nikolaynesov/laravel-serene.svg?style=flat-square)](https://packagist.org/packages/nikolaynesov/laravel-serene)
[![Total Downloads](https://img.shields.io/packagist/dt/nikolaynesov/laravel-serene.svg?style=flat-square)](https://packagist.org/packages/nikolaynesov/laravel-serene)

Graceful, noise-free, and rate-limited exception reporting for Laravel. Stop spamming your error tracking service with duplicate errors and get meaningful insights into how many times errors occurred and how many users were affected.

## Features

- **Rate Limiting**: Automatically throttles duplicate errors to prevent spam
- **Affected User Tracking**: Tracks all users affected by an error during the cooldown period
- **Metrics & Stats**: Provides occurrence and throttle statistics for each error
- **Provider Agnostic**: Works with Bugsnag, Sentry, or any custom error reporter
- **Cache Driver Compatible**: Works with any Laravel cache driver (Redis, Memcached, File, etc.)
- **Easy Integration**: Simple facade interface and automatic Laravel discovery

## Installation

Install the package via Composer:

```bash
composer require nikolaynesov/laravel-serene
```

The package will automatically register itself via Laravel's package discovery.

### Publish Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=serene-config
```

This will create a `config/serene.php` file.

## Configuration

```php
<?php

return [
    /*
     * The reporter provider class implementing ErrorReporter.
     * Built-in options:
     * - \Nikolaynesov\LaravelSerene\Providers\BugsnagReporter::class
     * - \Nikolaynesov\LaravelSerene\Providers\LogReporter::class
     */
    'provider' => \Nikolaynesov\LaravelSerene\Providers\BugsnagReporter::class,

    /*
     * Cooldown period in minutes (default: 30 minutes).
     * Same errors won't be reported more than once during this period.
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
     * Environment: SERENE_REPORTER_MAX_TRACKED_USERS
     * Default: 1000
     */
    'max_tracked_users' => env('SERENE_REPORTER_MAX_TRACKED_USERS', 1000),
];
```

### Environment Variables

Add these to your `.env` file to configure Serene:

```bash
# Error reporting cooldown period in minutes (default: 30)
SERENE_REPORTER_COOLDOWN=30

# Enable debug logging (default: false)
SERENE_REPORTER_DEBUG=false

# Maximum users to track per error (default: 1000)
SERENE_REPORTER_MAX_TRACKED_USERS=1000
```

All environment variables are optional. If not set, the defaults shown above will be used.

### Using with Bugsnag

If you're using Bugsnag, install the Bugsnag Laravel package:

```bash
composer require bugsnag/bugsnag-laravel
```

Then configure Bugsnag according to their [documentation](https://docs.bugsnag.com/platforms/php/laravel/).

Set the provider in `config/serene.php`:

```php
'provider' => \Nikolaynesov\LaravelSerene\Providers\BugsnagReporter::class,
```

### Using with Laravel Logs

To simply log errors to your Laravel logs:

```php
'provider' => \Nikolaynesov\LaravelSerene\Providers\LogReporter::class,
```

## Usage

### Integration with Laravel Exception Handler

The recommended way to use Serene is to integrate it with Laravel's exception handler. This automatically reports all exceptions with user context:

```php
<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Nikolaynesov\LaravelSerene\Facades\Serene;
use Throwable;

class Handler extends ExceptionHandler
{
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            Serene::report($e, [
                'user_id' => auth()->id(),
            ]);
        });
    }
}
```

This will automatically report all exceptions with the current user's ID, enabling proper user tracking and Bugsnag identification.

### Basic Usage

You can also use the `Serene` facade directly in your code:

```php
use Nikolaynesov\LaravelSerene\Facades\Serene;

try {
    // Your code that might throw an exception
    $user = User::findOrFail($id);
} catch (\Exception $e) {
    Serene::report($e);

    // Handle the error gracefully
    return response()->json(['error' => 'User not found'], 404);
}
```

### Tracking Affected Users

Track which users are affected by an error:

```php
use Nikolaynesov\LaravelSerene\Facades\Serene;

try {
    // Your code
    $this->processPayment($user, $amount);
} catch (\Exception $e) {
    Serene::report($e, [
        'user_id' => $user->id,
    ]);

    // Handle error
}
```

When the error is reported, the context will include:
- `affected_users`: Array of user IDs affected during the cooldown period (up to `max_tracked_users`)
- `affected_user_count`: Total number of affected users tracked
- `user_tracking_capped`: Boolean indicating if the user tracking limit was reached

**Important:** If using Bugsnag, the first affected user will be set as the primary user for the error report, enabling proper user filtering and search in Bugsnag's Users tab. All affected users remain visible in the metadata.

### Enhanced Bugsnag Integration

When using BugsnagReporter, you can provide additional context fields that will enrich the Bugsnag error report:

```php
Serene::report($exception, [
    'user_id' => $user->id,
    'user_email' => $user->email,      // Sets Bugsnag user email
    'user_name' => $user->name,        // Sets Bugsnag user name
    'severity' => 'warning',            // Sets Bugsnag severity level
    'app_version' => config('app.version'), // Adds to app metadata tab
]);
```

**Recognized fields for Bugsnag:**
- `user_email` - Sets email on Bugsnag user (visible in Users tab)
- `user_name` - Sets name on Bugsnag user
- `severity` - Sets error severity (`error`, `warning`, `info`)
- `app_version` - Adds application version to metadata

All fields remain in the `error_throttler` metadata tab for reference.

### Adding Custom Context

Add any additional context to help with debugging:

```php
Serene::report($exception, [
    'user_id' => $user->id,
    'order_id' => $order->id,
    'payment_method' => 'stripe',
    'amount' => $amount,
]);
```

### Custom Error Keys

By default, errors are grouped by exception class and message. You can provide a custom key for more granular control:

```php
// Group by API endpoint
$key = "api:payment:stripe";
Serene::report($exception, ['user_id' => $user->id], $key);

// Group by specific operation
$key = "order:{$order->id}:processing";
Serene::report($exception, ['order_id' => $order->id], $key);
```

### Dependency Injection

You can also use dependency injection instead of the facade:

```php
use Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter;

class PaymentController extends Controller
{
    public function __construct(
        protected RateLimitedErrorReporter $errorReporter
    ) {}

    public function process(Request $request)
    {
        try {
            // Process payment
        } catch (\Exception $e) {
            $this->errorReporter->report($e, [
                'user_id' => auth()->id(),
            ]);

            return back()->withErrors('Payment failed');
        }
    }
}
```

## How It Works

### Rate Limiting Logic

1. **First Occurrence**: When an error occurs for the first time, it's immediately reported to your error tracking service
2. **Cooldown Period**: A cooldown timer starts (default: 30 minutes)
3. **Subsequent Occurrences**: If the same error occurs again during the cooldown:
   - It's **not** reported (preventing spam)
   - User IDs are collected if provided (up to `max_tracked_users` limit)
   - Occurrence and throttle counters are incremented
4. **After Cooldown**: When the cooldown expires, the next occurrence will be reported with full statistics

### Reported Context

When an error is reported, the following context is automatically added:

```php
[
    'affected_users' => [1, 5, 12, 43],  // Array of user IDs (max 1000)
    'affected_user_count' => 4,           // Total affected users tracked
    'user_tracking_capped' => false,      // True if hit max_tracked_users limit
    'occurrences' => 15,                   // Total times error occurred
    'throttled' => 14,                     // Times error was throttled
    'reported_at' => '2025-12-05 18:30:00', // When reported
    'key' => 'auto:runtimeexception:abc123', // Error key
    // ... your custom context
]
```

### Debug Logging

Enable debug logging to monitor throttling behavior:

```bash
# .env
SERENE_REPORTER_DEBUG=true
```

Or in config:

```php
// config/serene.php
'debug' => true,
```

When enabled, Serene logs all activity:

```php
// When an error is reported (info level)
[Serene] auto:runtimeexception:abc123 reported
{
    "affected_users": [1, 5, 12],
    "count": 3,
    "occurrences": 15,
    "throttled": 14
}

// When an error is throttled (debug level)
[Serene] auto:runtimeexception:abc123 throttled
{
    "occurrences": 16,
    "throttled": 15
}
```

**Note:** Keep debug logging disabled in production to avoid log clutter. Only enable when investigating throttling issues.

## Creating Custom Providers

### Simple Custom Provider

You can create your own error reporter by implementing the `ErrorReporter` interface:

```php
<?php

namespace App\ErrorReporters;

use Nikolaynesov\LaravelSerene\Contracts\ErrorReporter;
use Throwable;

class SentryReporter implements ErrorReporter
{
    public function report(Throwable $exception, array $context = []): void
    {
        \Sentry\captureException($exception, [
            'extra' => $context,
        ]);
    }
}
```

Then configure it in `config/serene.php`:

```php
'provider' => \App\ErrorReporters\SentryReporter::class,
```

### Advanced Bugsnag Customization

For advanced Bugsnag integration beyond the built-in fields, extend the `BugsnagReporter`:

```php
<?php

namespace App\ErrorReporters;

use Nikolaynesov\LaravelSerene\Providers\BugsnagReporter;
use Throwable;

class EnhancedBugsnagReporter extends BugsnagReporter
{
    public function report(Throwable $exception, array $context = []): void
    {
        // Automatically enrich context with app-specific data
        $context['app_version'] = config('app.version');
        $context['environment'] = app()->environment();

        // Add authenticated user details
        if ($user = auth()->user()) {
            $context['user_id'] = $user->id;
            $context['user_email'] = $user->email;
            $context['user_name'] = $user->name;
            $context['user_role'] = $user->role;
            $context['subscription_tier'] = $user->subscription?->tier;
        }

        // Determine severity based on exception type
        $context['severity'] = $this->determineSeverity($exception);

        // Call parent to handle standard reporting + custom enrichment
        parent::report($exception, $context);
    }

    protected function determineSeverity(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpException => 'warning',
            $exception instanceof \InvalidArgumentException => 'info',
            default => 'error',
        };
    }
}
```

**Advanced customization with direct Bugsnag API access:**

```php
<?php

namespace App\ErrorReporters;

use Bugsnag\BugsnagLaravel\Facades\Bugsnag;
use Nikolaynesov\LaravelSerene\Providers\BugsnagReporter;
use Throwable;

class CustomBugsnagReporter extends BugsnagReporter
{
    public function report(Throwable $exception, array $context = []): void
    {
        Bugsnag::notifyException($exception, function ($report) use ($context) {
            // Standard user identification (inherited behavior)
            if (!empty($context['affected_users'])) {
                $report->setUser([
                    'id' => (string) $context['affected_users'][0],
                    'email' => $context['user_email'] ?? null,
                    'name' => $context['user_name'] ?? null,
                ]);
            }

            // Add custom metadata tabs
            $report->addMetaData('request', [
                'url' => request()->fullUrl(),
                'method' => request()->method(),
                'ip' => request()->ip(),
            ]);

            $report->addMetaData('session', [
                'id' => session()->getId(),
                'started_at' => session()->get('started_at'),
            ]);

            // Custom grouping
            $report->setGroupingHash(
                $exception->getFile() . ':' . $exception->getLine()
            );

            // Add breadcrumbs
            $report->leaveBreadcrumb('User Action', 'process', [
                'action' => $context['action'] ?? 'unknown',
            ]);

            // Set context for better organization
            $report->setContext($context['context'] ?? 'General');

            // Standard metadata
            $report->setMetaData(['error_throttler' => $context]);
        });
    }
}
```

Configure your custom reporter:

```php
// config/serene.php
'provider' => \App\ErrorReporters\EnhancedBugsnagReporter::class,
```

This approach gives you complete control over the Bugsnag integration while maintaining Serene's rate limiting and user tracking features.

## Advanced Configuration

### Adjusting Cooldown Period

Change the cooldown period based on your needs:

```bash
# .env

# 5 minutes for high-frequency errors
SERENE_REPORTER_COOLDOWN=5

# 24 hours for low-frequency errors
SERENE_REPORTER_COOLDOWN=1440

# Default: 30 minutes
SERENE_REPORTER_COOLDOWN=30
```

### Per-Environment Configuration

Configure different settings per environment using `.env` files:

```bash
# .env.local (development)
SERENE_REPORTER_COOLDOWN=5
SERENE_REPORTER_DEBUG=true
SERENE_REPORTER_MAX_TRACKED_USERS=100

# .env.production (production)
SERENE_REPORTER_COOLDOWN=30
SERENE_REPORTER_DEBUG=false
SERENE_REPORTER_MAX_TRACKED_USERS=1000
```

Or dynamically in config:

```php
// config/serene.php

return [
    'provider' => env('APP_ENV') === 'local'
        ? \Nikolaynesov\LaravelSerene\Providers\LogReporter::class
        : \Nikolaynesov\LaravelSerene\Providers\BugsnagReporter::class,

    'cooldown' => env('SERENE_REPORTER_COOLDOWN', 30),
    'debug' => env('SERENE_REPORTER_DEBUG', false),
    'max_tracked_users' => env('SERENE_REPORTER_MAX_TRACKED_USERS', 1000),
];
```

### Cache Memory Management

Serene caps user tracking at `max_tracked_users` (default: 1000) per error to prevent cache memory issues during widespread errors.

**Memory calculation:**
- 1000 users × 8 bytes = ~8KB per error
- 100 concurrent unique errors = ~800KB total
- Safe for most cache systems (Redis, Memcached, etc.)

**For high-traffic applications:**
```bash
# .env
SERENE_REPORTER_MAX_TRACKED_USERS=100
```

**For low-traffic applications:**
```bash
# .env
SERENE_REPORTER_MAX_TRACKED_USERS=5000
```

When `user_tracking_capped` is `true` in the error context, it indicates a widespread issue affecting many users.

## Testing

Run the test suite:

```bash
composer test
```

Run static analysis:

```bash
composer phpstan
```

## Requirements

- PHP 8.1 or higher
- Laravel 10.x or 11.x

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Security

If you discover any security-related issues, please email security@example.com instead of using the issue tracker.

## Credits

- [Nikolay Nesov](https://github.com/nikolaynesov)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
