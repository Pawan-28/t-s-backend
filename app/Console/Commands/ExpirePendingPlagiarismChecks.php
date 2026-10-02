<?php

namespace App\Console\Commands;

use App\Services\Plagiarism\PlagiarismCheckService;
use Illuminate\Console\Command;

class ExpirePendingPlagiarismChecks extends Command
{
    protected $signature = 'plagiarism:expire-pending {--hours= : Override portal.copyleaks.pending_timeout_hours}';

    protected $description = 'Mark plagiarism checks still PENDING after the timeout as FAILED';

    public function handle(PlagiarismCheckService $service): int
    {
        $hours = $this->option('hours');
        $n = $service->expirePending($hours !== null && ctype_digit((string) $hours) ? (int) $hours : null);
        $this->info("Expired {$n} pending plagiarism check(s).");

        return self::SUCCESS;
    }
}
