<?php

use Bugsnag\BugsnagLaravel\Facades\Bugsnag;
use Nikolaynesov\LaravelSerene\Providers\BugsnagReporter;

test('calls Bugsnag notifyException with exception and callback', function () {
    $exception = new RuntimeException('Test error');

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->with(
            $exception,
            \Mockery::type('callable')
        );

    $reporter = new BugsnagReporter();
    $reporter->report($exception);
});

test('passes context as metadata under error_throttler key', function () {
    $exception = new RuntimeException('Test');
    $context = [
        'user_id' => 123,
        'custom' => 'value',
    ];

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->withArgs(function ($ex, $callback) use ($exception, $context) {
            // Verify exception
            if ($ex !== $exception) {
                return false;
            }

            // Create mock report to test callback
            $report = new class {
                public array $metadata = [];

                public function setMetaData(array $data): void
                {
                    $this->metadata = $data;
                }
            };

            // Execute callback
            $callback($report);

            // Verify metadata structure
            return isset($report->metadata['error_throttler'])
                && $report->metadata['error_throttler'] === $context;
        });

    $reporter = new BugsnagReporter();
    $reporter->report($exception, $context);
});

test('handles empty context', function () {
    $exception = new RuntimeException('Test');

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->withArgs(function ($ex, $callback) use ($exception) {
            if ($ex !== $exception) {
                return false;
            }

            $report = new class {
                public array $metadata = [];

                public function setMetaData(array $data): void
                {
                    $this->metadata = $data;
                }
            };

            $callback($report);

            return isset($report->metadata['error_throttler'])
                && $report->metadata['error_throttler'] === [];
        });

    $reporter = new BugsnagReporter();
    $reporter->report($exception);
});

test('handles different exception types', function () {
    $exception1 = new RuntimeException('Runtime error');
    $exception2 = new InvalidArgumentException('Invalid argument');

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->with(
            $exception1,
            \Mockery::type('callable')
        );

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->with(
            $exception2,
            \Mockery::type('callable')
        );

    $reporter = new BugsnagReporter();

    $reporter->report($exception1);
    $reporter->report($exception2);
});

test('metadata is nested under error_throttler key', function () {
    $exception = new RuntimeException('Test');
    $context = ['key' => 'value'];

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->withArgs(function ($ex, $callback) use ($context) {
            $report = new class {
                public array $metadata = [];

                public function setMetaData(array $data): void
                {
                    $this->metadata = $data;
                }
            };

            $callback($report);

            return array_key_exists('error_throttler', $report->metadata)
                && $report->metadata['error_throttler'] === $context;
        });

    $reporter = new BugsnagReporter();
    $reporter->report($exception, $context);
});

test('preserves all context fields in metadata', function () {
    $exception = new RuntimeException('Test');
    $context = [
        'user_id' => 123,
        'affected_users' => [1, 2, 3],
        'affected_user_count' => 3,
        'occurrences' => 5,
        'throttled' => 4,
        'reported_at' => '2025-12-05 10:00:00',
        'key' => 'auto:test',
    ];

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->withArgs(function ($ex, $callback) use ($context) {
            $report = new class {
                public array $metadata = [];

                public function setMetaData(array $data): void
                {
                    $this->metadata = $data;
                }
            };

            $callback($report);

            return $report->metadata['error_throttler'] === $context;
        });

    $reporter = new BugsnagReporter();
    $reporter->report($exception, $context);
});

test('callback receives report object and sets metadata correctly', function () {
    $exception = new RuntimeException('Test');
    $context = ['custom_field' => 'custom_value'];

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->withArgs(function ($ex, $callback) use ($exception, $context) {
            // Verify exception is passed
            expect($ex)->toBe($exception);

            // Create a proper mock report
            $report = new class {
                public array $metadata = [];
                public int $setMetaDataCalls = 0;

                public function setMetaData(array $data): void
                {
                    $this->metadata = $data;
                    $this->setMetaDataCalls++;
                }
            };

            // Execute callback
            $callback($report);

            // Verify setMetaData was called exactly once
            expect($report->setMetaDataCalls)->toBe(1);

            // Verify metadata structure
            expect($report->metadata)->toBe(['error_throttler' => $context]);

            return true;
        });

    $reporter = new BugsnagReporter();
    $reporter->report($exception, $context);
});

test('passes exact exception instance not a type', function () {
    $exception = new RuntimeException('Exact exception test');

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->withArgs(function ($ex, $callback) use ($exception) {
            // Must be the exact same instance
            return $ex === $exception && is_callable($callback);
        });

    $reporter = new BugsnagReporter();
    $reporter->report($exception);
});

test('sets first affected user as Bugsnag user', function () {
    $exception = new RuntimeException('Test');
    $context = [
        'affected_users' => [123, 456, 789],
        'affected_user_count' => 3,
    ];

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->withArgs(function ($ex, $callback) use ($context) {
            $report = new class {
                public ?array $user = null;
                public array $metadata = [];

                public function setUser(array $data): void
                {
                    $this->user = $data;
                }

                public function setMetaData(array $data): void
                {
                    $this->metadata = $data;
                }
            };

            $callback($report);

            // Verify user is set to first affected user
            return $report->user === ['id' => '123']
                && $report->metadata['error_throttler'] === $context;
        });

    $reporter = new BugsnagReporter();
    $reporter->report($exception, $context);
});

test('does not set user when no affected users', function () {
    $exception = new RuntimeException('Test');
    $context = [
        'affected_users' => [],
        'affected_user_count' => 0,
    ];

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->withArgs(function ($ex, $callback) use ($context) {
            $report = new class {
                public ?array $user = null;
                public array $metadata = [];

                public function setUser(array $data): void
                {
                    $this->user = $data;
                }

                public function setMetaData(array $data): void
                {
                    $this->metadata = $data;
                }
            };

            $callback($report);

            // Verify user is NOT set
            return $report->user === null
                && $report->metadata['error_throttler'] === $context;
        });

    $reporter = new BugsnagReporter();
    $reporter->report($exception, $context);
});

test('converts user id to string for Bugsnag', function () {
    $exception = new RuntimeException('Test');
    $context = [
        'affected_users' => [999], // Integer user ID
    ];

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->withArgs(function ($ex, $callback) {
            $report = new class {
                public ?array $user = null;
                public array $metadata = [];

                public function setUser(array $data): void
                {
                    $this->user = $data;
                }

                public function setMetaData(array $data): void
                {
                    $this->metadata = $data;
                }
            };

            $callback($report);

            // Verify user ID is converted to string
            return $report->user === ['id' => '999'];
        });

    $reporter = new BugsnagReporter();
    $reporter->report($exception, $context);
});

test('sets first user even when many affected', function () {
    $exception = new RuntimeException('Test');
    $manyUsers = range(1, 100); // 100 users
    $context = [
        'affected_users' => $manyUsers,
        'affected_user_count' => 100,
    ];

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->withArgs(function ($ex, $callback) {
            $report = new class {
                public ?array $user = null;
                public array $metadata = [];

                public function setUser(array $data): void
                {
                    $this->user = $data;
                }

                public function setMetaData(array $data): void
                {
                    $this->metadata = $data;
                }

                public function setSeverity(string $severity): void {}
                public function addMetaData(string $tab, array $data): void {}
            };

            $callback($report);

            // Verify user is set to first user (1)
            return $report->user === ['id' => '1'];
        });

    $reporter = new BugsnagReporter();
    $reporter->report($exception, $context);
});

test('sets user email when provided', function () {
    $exception = new RuntimeException('Test');
    $context = [
        'affected_users' => [123],
        'user_email' => 'user@example.com',
    ];

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->withArgs(function ($ex, $callback) {
            $report = new class {
                public ?array $user = null;
                public array $metadata = [];

                public function setUser(array $data): void
                {
                    $this->user = $data;
                }

                public function setMetaData(array $data): void
                {
                    $this->metadata = $data;
                }

                public function setSeverity(string $severity): void {}
                public function addMetaData(string $tab, array $data): void {}
            };

            $callback($report);

            return $report->user === ['id' => '123', 'email' => 'user@example.com'];
        });

    $reporter = new BugsnagReporter();
    $reporter->report($exception, $context);
});

test('sets user email and name when provided', function () {
    $exception = new RuntimeException('Test');
    $context = [
        'affected_users' => [456],
        'user_email' => 'john@example.com',
        'user_name' => 'John Doe',
    ];

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->withArgs(function ($ex, $callback) {
            $report = new class {
                public ?array $user = null;
                public array $metadata = [];

                public function setUser(array $data): void
                {
                    $this->user = $data;
                }

                public function setMetaData(array $data): void
                {
                    $this->metadata = $data;
                }

                public function setSeverity(string $severity): void {}
                public function addMetaData(string $tab, array $data): void {}
            };

            $callback($report);

            return $report->user === [
                'id' => '456',
                'email' => 'john@example.com',
                'name' => 'John Doe',
            ];
        });

    $reporter = new BugsnagReporter();
    $reporter->report($exception, $context);
});

test('sets severity when provided', function () {
    $exception = new RuntimeException('Test');
    $context = [
        'affected_users' => [123],
        'severity' => 'warning',
    ];

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->withArgs(function ($ex, $callback) {
            $report = new class {
                public ?array $user = null;
                public array $metadata = [];
                public ?string $severity = null;

                public function setUser(array $data): void
                {
                    $this->user = $data;
                }

                public function setMetaData(array $data): void
                {
                    $this->metadata = $data;
                }

                public function setSeverity(string $severity): void
                {
                    $this->severity = $severity;
                }

                public function addMetaData(string $tab, array $data): void {}
            };

            $callback($report);

            return $report->severity === 'warning';
        });

    $reporter = new BugsnagReporter();
    $reporter->report($exception, $context);
});

test('adds app version to metadata when provided', function () {
    $exception = new RuntimeException('Test');
    $context = [
        'affected_users' => [123],
        'app_version' => '1.2.3',
    ];

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->withArgs(function ($ex, $callback) {
            $report = new class {
                public ?array $user = null;
                public array $metadata = [];
                public array $appMetadata = [];

                public function setUser(array $data): void
                {
                    $this->user = $data;
                }

                public function setMetaData(array $data): void
                {
                    $this->metadata = $data;
                }

                public function setSeverity(string $severity): void {}

                public function addMetaData(string $tab, array $data): void
                {
                    if ($tab === 'app') {
                        $this->appMetadata = $data;
                    }
                }
            };

            $callback($report);

            return $report->appMetadata === ['version' => '1.2.3'];
        });

    $reporter = new BugsnagReporter();
    $reporter->report($exception, $context);
});

test('combines all enrichment fields together', function () {
    $exception = new RuntimeException('Test');
    $context = [
        'affected_users' => [789],
        'user_email' => 'jane@example.com',
        'user_name' => 'Jane Smith',
        'severity' => 'error',
        'app_version' => '2.0.0',
    ];

    Bugsnag::shouldReceive('notifyException')
        ->once()
        ->withArgs(function ($ex, $callback) use ($context) {
            $report = new class {
                public ?array $user = null;
                public array $metadata = [];
                public ?string $severity = null;
                public array $appMetadata = [];

                public function setUser(array $data): void
                {
                    $this->user = $data;
                }

                public function setMetaData(array $data): void
                {
                    $this->metadata = $data;
                }

                public function setSeverity(string $severity): void
                {
                    $this->severity = $severity;
                }

                public function addMetaData(string $tab, array $data): void
                {
                    if ($tab === 'app') {
                        $this->appMetadata = $data;
                    }
                }
            };

            $callback($report);

            return $report->user === ['id' => '789', 'email' => 'jane@example.com', 'name' => 'Jane Smith']
                && $report->severity === 'error'
                && $report->appMetadata === ['version' => '2.0.0']
                && isset($report->metadata['error_throttler']);
        });

    $reporter = new BugsnagReporter();
    $reporter->report($exception, $context);
});
