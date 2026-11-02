<?php

namespace App\Console\Commands;

use App\Services\AutomaticBillPaymentService;
use Illuminate\Console\Command;

class ProcessAutomaticBillPayments extends Command
{
    protected $signature = 'bills:process-automatic-payments';
    protected $description = 'Process automatic Ledger bill payments that are due';

    public function handle(AutomaticBillPaymentService $service): int
    {
        $stats = $service->processDue();
        $this->info("Paid: {$stats['paid']} · perlu perhatian: {$stats['attention']} · dilewati: {$stats['skipped']}");
        return self::SUCCESS;
    }
}
