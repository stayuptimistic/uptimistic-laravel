<?php

namespace Uptimistic\Laravel\Console;

use Illuminate\Console\Command;
use Uptimistic\Laravel\Http\VerifySignature;
use Uptimistic\Laravel\Report;

/**
 * Shows what the report would tell Uptimistic, and whether it is set up.
 */
class ReportCommand extends Command
{
    /** @var string */
    protected $signature = 'uptimistic:report';

    /** @var string */
    protected $description = 'Show the report your Uptimistic monitor receives';

    public function handle(Report $report): int
    {
        $this->line((string) json_encode($report->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('');

        if (strlen((string) config('uptimistic.secret')) < VerifySignature::MIN_SECRET_LENGTH) {
            $this->warn('UPTIMISTIC_REPORT_SECRET is not set (or shorter than 32 characters): the report answers nobody.');

            return 1;
        }

        $this->info('Monitor URL: '.url((string) config('uptimistic.path')));

        return 0;
    }
}
