<?php

namespace Nikolaynesov\LaravelSerene\Tests\Helpers;

use Nikolaynesov\LaravelSerene\Contracts\ErrorReporter;
use Throwable;

class FakeErrorReporter implements ErrorReporter
{
    public array $reports = [];

    public function report(Throwable $exception, array $context = []): void
    {
        $this->reports[] = [
            'exception' => $exception,
            'context' => $context,
            'time' => now(),
        ];
    }

    public function assertReported(string $exceptionClass): void
    {
        $found = collect($this->reports)->contains(
            fn ($report) => $report['exception'] instanceof $exceptionClass
        );

        if (!$found) {
            throw new \PHPUnit\Framework\AssertionFailedError(
                "Failed asserting that {$exceptionClass} was reported."
            );
        }
    }

    public function assertNotReported(string $exceptionClass): void
    {
        $found = collect($this->reports)->contains(
            fn ($report) => $report['exception'] instanceof $exceptionClass
        );

        if ($found) {
            throw new \PHPUnit\Framework\AssertionFailedError(
                "Failed asserting that {$exceptionClass} was not reported."
            );
        }
    }

    public function assertReportCount(int $count): void
    {
        $actual = count($this->reports);

        if ($actual !== $count) {
            throw new \PHPUnit\Framework\AssertionFailedError(
                "Failed asserting that report count is {$count}. Actual: {$actual}"
            );
        }
    }

    public function assertContextContains(string $key, mixed $value): void
    {
        $found = collect($this->reports)->contains(function ($report) use ($key, $value) {
            return isset($report['context'][$key]) && $report['context'][$key] === $value;
        });

        if (!$found) {
            throw new \PHPUnit\Framework\AssertionFailedError(
                "Failed asserting that context contains [{$key} => {$value}]."
            );
        }
    }

    public function getLastReport(): ?array
    {
        return end($this->reports) ?: null;
    }

    public function reset(): void
    {
        $this->reports = [];
    }
}
