<?php

namespace App\Http\Controllers;

use App\Domain\SalesCycle;
use App\Models\Incaricata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class NetworkController extends Controller
{
    public function show(Request $request)
    {
        /** @var Incaricata $me */
        $me = $request->attributes->get('seller');
        $cycle = SalesCycle::current();

        // Upline chain up to the top.
        $uplines = [];
        $cursor = $me->upline;
        while ($cursor && count($uplines) < 20) {
            $uplines[] = $cursor->summary();
            $cursor = $cursor->upline;
        }

        // Whole downline, counted per level, with a recursive CTE (MariaDB and SQLite both support it).
        $levels = DB::select('
            WITH RECURSIVE tree (id, lvl) AS (
                SELECT id, 1 FROM incaricate WHERE upline_id = ?
                UNION ALL
                SELECT i.id, t.lvl + 1 FROM incaricate i JOIN tree t ON i.upline_id = t.id WHERE t.lvl < 12
            )
            SELECT lvl, COUNT(*) AS n FROM tree GROUP BY lvl ORDER BY lvl', [$me->id]);

        $volumes = DB::table('orders')
            ->whereBetween('placed_at', [$cycle->start(), $cycle->end()->subSecond()])
            ->whereIn('seller_id', $me->downline()->pluck('id'))
            ->groupBy('seller_id')
            ->pluck(DB::raw('SUM(total_cents)'), 'seller_id');

        $direct = $me->downline()->withCount('downline')->orderBy('last_name')->limit(50)->get()
            ->map(fn (Incaricata $s) => $s->summary() + [
                'downline' => $s->downline_count,
                'volume_cents' => (int) ($volumes[$s->id] ?? 0),
            ]);

        return Inertia::render('Rete', [
            'cycle' => $cycle->label(),
            'uplines' => array_reverse($uplines),
            'levels' => $levels,
            'direct' => $direct,
        ]);
    }
}
