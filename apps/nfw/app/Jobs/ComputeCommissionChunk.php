<?php

namespace App\Jobs;

use App\Domain\CommissionCalculator;
use App\Domain\SalesCycle;
use App\Models\CommissionRun;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Step 2 (parallel): compute commissions for one range of order IDs.
 * CPU-bound: more vCPU on the commissions worker = shorter run.
 */
class ComputeCommissionChunk implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 900;

    /** @var array<int, array> per-process cache of the hierarchy, keyed by run */
    private static array $trees = [];

    public function __construct(public int $runId, public int $chunkNo, public int $fromId, public int $toId) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $run = CommissionRun::findOrFail($this->runId);
        $cycle = SalesCycle::number($run->cycle);
        $tree = self::$trees[$this->runId] ??= unserialize(gzuncompress(Cache::get(PrepareCommissionRun::treeKey($this->runId))));

        $orders = DB::table('orders')
            ->whereBetween('id', [$this->fromId, $this->toId])
            ->whereBetween('placed_at', [$cycle->start(), $cycle->end()->subSecond()])
            ->get(['seller_id', 'total_cents'])
            ->map(fn ($o) => [(int) $o->seller_id, (int) $o->total_cents]);

        $calculator = new CommissionCalculator($tree['uplines'], $tree['active'], (int) config('commissions.complexity'));
        $result = $calculator->compute($orders);

        $volumes = [];
        foreach ($orders as [$seller, $total]) {
            $volumes[$seller] = ($volumes[$seller] ?? 0) + $total;
        }
        $result['volume'] = $volumes;

        DB::table('commission_chunks')->upsert([
            'run_id' => $this->runId,
            'chunk_no' => $this->chunkNo,
            'payload' => base64_encode(gzcompress(serialize($result))),
        ], ['run_id', 'chunk_no'], ['payload']);
    }
}
