<?php

namespace App\Jobs;

use App\Domain\CommissionCalculator;
use App\Domain\SalesCycle;
use App\Models\CommissionRun;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Step 1: load the hierarchy and the active sellers, split the cycle's orders
 * into chunks, and fan them out as a batch on the "commissions" queue.
 */
class PrepareCommissionRun implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public int $runId) {}

    public function handle(): void
    {
        $run = CommissionRun::findOrFail($this->runId);
        $cycle = SalesCycle::number($run->cycle);
        $run->update(['status' => 'running', 'started_at' => now()]);

        $uplines = DB::table('incaricate')->pluck('upline_id', 'id')->map(fn ($u) => (int) $u)->all();

        $active = DB::table('orders')
            ->whereBetween('placed_at', [$cycle->start(), $cycle->end()->subSecond()])
            ->groupBy('seller_id')
            ->havingRaw('SUM(total_cents) >= ?', [CommissionCalculator::ACTIVE_THRESHOLD_CENTS])
            ->pluck('seller_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        Cache::put(self::treeKey($run->id), gzcompress(serialize(['uplines' => $uplines, 'active' => $active])), now()->addDay());

        $range = DB::table('orders')
            ->whereBetween('placed_at', [$cycle->start(), $cycle->end()->subSecond()])
            ->selectRaw('MIN(id) AS min_id, MAX(id) AS max_id, COUNT(*) AS n')
            ->first();

        $size = (int) config('commissions.chunk_size');
        $jobs = [];
        if ($range->n > 0) {
            for ($from = (int) $range->min_id, $i = 0; $from <= $range->max_id; $from += $size, $i++) {
                $jobs[] = new ComputeCommissionChunk($run->id, $i, $from, $from + $size - 1);
            }
        }

        $run->update(['chunks' => count($jobs), 'orders_count' => (int) $range->n]);

        $runId = $run->id;
        Bus::batch($jobs)
            ->name("commissions-run-{$runId}")
            ->onQueue('commissions')
            ->then(fn (Batch $batch) => FinalizeCommissionRun::dispatch($runId)->onQueue('commissions'))
            ->catch(fn (Batch $batch, Throwable $e) => CommissionRun::whereKey($runId)->update(['status' => 'failed', 'note' => mb_substr($e->getMessage(), 0, 250)]))
            ->allowFailures(false)
            ->dispatch();

        if ($jobs === []) {
            FinalizeCommissionRun::dispatch($runId)->onQueue('commissions');
        }
    }

    public static function treeKey(int $runId): string
    {
        return "commissions:run:{$runId}:tree";
    }
}
