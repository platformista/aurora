<?php

namespace App\Console\Commands;

use App\Domain\CommissionRunner;
use App\Domain\SalesCycle;
use Illuminate\Console\Command;

class RunCommissions extends Command
{
    protected $signature = 'commissions:run {--period=current : "current", "previous" or a cycle number} {--trigger=cli}';

    protected $description = 'Queue a commission run on the dedicated Horizon queue';

    public function handle(CommissionRunner $runner): int
    {
        $period = (string) $this->option('period');
        $cycle = match ($period) {
            'current' => SalesCycle::current(),
            'previous' => SalesCycle::current()->previous(),
            default => SalesCycle::number((int) $period),
        };

        $run = $runner->start($cycle, (string) $this->option('trigger'));
        $this->components->info("Run #{$run->id} queued for {$cycle->label()}. Follow it on /admin or /horizon.");

        return self::SUCCESS;
    }
}
