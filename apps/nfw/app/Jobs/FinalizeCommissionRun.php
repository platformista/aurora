<?php

namespace App\Jobs;

use App\Models\CommissionRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Step 3: merge the chunks, write one row per beneficiary, record the duration. */
class FinalizeCommissionRun implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public int $runId) {}

    public function handle(): void
    {
        $run = CommissionRun::findOrFail($this->runId);
        $personal = $network = $volume = [];

        foreach (DB::table('commission_chunks')->where('run_id', $run->id)->orderBy('chunk_no')->cursor() as $chunk) {
            $part = unserialize(gzuncompress(base64_decode($chunk->payload)));
            foreach (['personal', 'network', 'volume'] as $kind) {
                foreach ($part[$kind] as $seller => $cents) {
                    ${$kind}[$seller] = (${$kind}[$seller] ?? 0) + $cents;
                }
            }
        }

        $sellers = array_keys($personal + $network);
        $rows = array_map(fn ($s) => [
            'run_id' => $run->id,
            'seller_id' => $s,
            'cycle' => $run->cycle,
            'personal_volume_cents' => $volume[$s] ?? 0,
            'personal_cents' => $personal[$s] ?? 0,
            'network_cents' => $network[$s] ?? 0,
        ], $sellers);

        DB::transaction(function () use ($run, $rows) {
            DB::table('commissions')->where('run_id', $run->id)->delete();
            foreach (array_chunk($rows, 1000) as $batch) {
                DB::table('commissions')->insert($batch);
            }
            DB::table('commission_chunks')->where('run_id', $run->id)->delete();
        });

        $finished = now();
        $run->update([
            'status' => 'completed',
            'beneficiaries_count' => count($rows),
            'total_cents' => array_sum($personal) + array_sum($network),
            'finished_at' => $finished,
            'duration_ms' => (int) $run->started_at->diffInMilliseconds($finished, true),
            'note' => 'complexity='.config('commissions.complexity').', processi='.config('horizon.commission_processes'),
        ]);

        Cache::forget(PrepareCommissionRun::treeKey($run->id));
    }
}
