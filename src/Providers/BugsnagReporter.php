<?php

namespace Nikolaynesov\LaravelSerene\Providers;


use Bugsnag\BugsnagLaravel\Facades\Bugsnag;
use Nikolaynesov\LaravelSerene\Contracts\ErrorReporter;
use Throwable;

class BugsnagReporter implements ErrorReporter
{
    /**
     * Whether the resolved group key should drive Bugsnag's grouping hash.
     */
    protected bool $setGroupingHash;

    public function __construct(?bool $setGroupingHash = null)
    {
        $this->setGroupingHash = $setGroupingHash
            ?? (bool) config('serene.set_grouping_hash', true);
    }

    public function report(Throwable $exception, array $context = []): void
    {
        $setGroupingHash = $this->setGroupingHash;

        Bugsnag::notifyException($exception, function ($report) use ($context, $setGroupingHash) {
            // Drive Bugsnag grouping with the resolved group key so that
            // getErrorGroup() / explicit keys collapse into one error.
            // RateLimitedErrorReporter always sets $context['key'] before
            // reporting; the empty() guard covers direct callers that bypass it.
            if ($setGroupingHash && !empty($context['key'])) {
                $report->setGroupingHash((string) $context['key']);
            }

            // Set user identification with optional email and name
            if (!empty($context['affected_users'])) {
                $userData = [
                    'id' => (string) $context['affected_users'][0],
                ];

                if (isset($context['user_email'])) {
                    $userData['email'] = $context['user_email'];
                }

                if (isset($context['user_name'])) {
                    $userData['name'] = $context['user_name'];
                }

                $report->setUser($userData);
            }

            // Set severity if provided
            if (isset($context['severity'])) {
                $report->setSeverity($context['severity']);
            }

            // Add app version to metadata if provided
            if (isset($context['app_version'])) {
                $report->addMetaData('app', [
                    'version' => $context['app_version'],
                ]);
            }

            // Add all context as metadata under error_throttler tab
            $report->setMetaData(['error_throttler' => $context]);
        });
    }

}