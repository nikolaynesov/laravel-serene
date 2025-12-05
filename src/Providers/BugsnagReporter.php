<?php

namespace Nikolaynesov\LaravelSerene\Providers;


use Bugsnag\BugsnagLaravel\Facades\Bugsnag;
use Nikolaynesov\LaravelSerene\Contracts\ErrorReporter;
use Throwable;

class BugsnagReporter implements ErrorReporter
{

    public function report(Throwable $exception, array $context = []): void
    {
        Bugsnag::notifyException($exception, function ($report) use ($context) {
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