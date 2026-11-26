<?php

namespace App\Domain;

use App\Jobs\PrepareCommissionRun;
use App\Models\CommissionRun;

/** Entry point shared by the cron, the UI button and the artisan command. */
class CommissionRunner
{
    public function start(SalesCycle $cycle, string $triggeredBy): CommissionRun
    {
        $run = CommissionRun::create([
            'cycle' => $cycle->number,
            'status' => 'queued',
            'triggered_by' => $triggeredBy,
            'queued_at' => now(),
        ]);

        PrepareCommissionRun::dispatch($run->id)->onQueue('commissions');

        return $run;
    }
}
