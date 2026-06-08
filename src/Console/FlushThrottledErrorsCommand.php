<?php

namespace Nikolaynesov\LaravelSerene\Console;

use Illuminate\Console\Command;
use Nikolaynesov\LaravelSerene\Services\RateLimitedErrorReporter;

class FlushThrottledErrorsCommand extends Command
{
    protected $signature = 'serene:flush-throttled
                            {--dry-run : Display what would be flushed without actually flushing}';

    protected $description = 'Flush throttled errors and report accumulated statistics';

    public function handle(RateLimitedErrorReporter $reporter): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('Running in dry-run mode (no errors will be reported)');
        }

        $this->info('Scanning for throttled errors...');

        $flushed = $reporter->flushThrottledErrors($dryRun);

        if (empty($flushed)) {
            $this->info('No throttled errors found.');
            return self::SUCCESS;
        }

        $this->info('Flushed ' . count($flushed) . ' throttled error(s):');

        foreach ($flushed as $result) {
            $this->line(sprintf(
                '  - %s: %d occurrences (%d throttled)',
                $result['key'],
                $result['occurrences'],
                $result['throttled']
            ));
        }

        if ($dryRun) {
            $this->warn('Dry-run mode: No errors were actually reported.');
        }

        return self::SUCCESS;
    }
}
