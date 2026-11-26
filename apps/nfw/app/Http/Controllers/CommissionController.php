<?php

namespace App\Http\Controllers;

use App\Domain\SalesCycle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CommissionController extends Controller
{
    public function show(Request $request)
    {
        $me = $request->attributes->get('seller');
        $cycle = SalesCycle::current();

        // Latest completed run per cycle for this seller.
        $rows = DB::table('commissions')
            ->join('commission_runs', 'commission_runs.id', '=', 'commissions.run_id')
            ->where('commissions.seller_id', $me->id)
            ->where('commission_runs.status', 'completed')
            ->orderByDesc('commission_runs.id')
            ->limit(20)
            ->get(['commission_runs.id as run_id', 'commission_runs.cycle', 'commission_runs.finished_at',
                'personal_volume_cents', 'personal_cents', 'network_cents'])
            ->map(fn ($r) => [
                'run_id' => $r->run_id,
                'cycle' => SalesCycle::number($r->cycle)->label(),
                'finished_at' => $r->finished_at,
                'personal_volume_cents' => (int) $r->personal_volume_cents,
                'personal_cents' => (int) $r->personal_cents,
                'network_cents' => (int) $r->network_cents,
            ]);

        $volume = (int) DB::table('orders')
            ->where('seller_id', $me->id)
            ->whereBetween('placed_at', [$cycle->start(), $cycle->end()->subSecond()])
            ->sum('total_cents');

        return Inertia::render('Provvigioni', [
            'cycle' => $cycle->label(),
            'volume_cents' => $volume,
            'threshold_cents' => \App\Domain\CommissionCalculator::ACTIVE_THRESHOLD_CENTS,
            'rows' => $rows,
        ]);
    }
}
