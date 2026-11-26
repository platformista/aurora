<?php

namespace Tests\Feature;

use App\Domain\CommissionCalculator;
use App\Domain\CommissionRunner;
use App\Domain\DemoData;
use App\Domain\SalesCycle;
use App\Models\CommissionRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommissionRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_run_splits_into_chunks_and_matches_a_single_pass(): void
    {
        config(['commissions.chunk_size' => 250, 'commissions.complexity' => 1]);
        $data = app(DemoData::class);
        $data->products();
        $data->sellers(200);
        $data->orders(3000, 200);

        $cycle = SalesCycle::current();
        $run = app(CommissionRunner::class)->start($cycle, 'test');   // sync queue in tests
        $run->refresh();

        $this->assertSame('completed', $run->status);
        $this->assertGreaterThan(1, $run->chunks);
        $this->assertNotNull($run->duration_ms);

        // Reference: the same rules in one pass over all orders.
        $orders = DB::table('orders')->whereBetween('placed_at', [$cycle->start(), $cycle->end()->subSecond()])->get();
        $uplines = DB::table('incaricate')->pluck('upline_id', 'id')->map(fn ($u) => (int) $u)->all();
        $volumes = $orders->groupBy('seller_id')->map->sum('total_cents');
        $active = $volumes->filter(fn ($v) => $v >= CommissionCalculator::ACTIVE_THRESHOLD_CENTS)->map(fn () => true)->all();
        $expected = (new CommissionCalculator($uplines, $active))->compute($orders->map(fn ($o) => [(int) $o->seller_id, (int) $o->total_cents]));

        $this->assertSame($orders->count(), $run->orders_count);
        $this->assertSame(array_sum($expected['personal']) + array_sum($expected['network']), (int) $run->total_cents);
        $this->assertSame(array_sum($expected['network']), (int) DB::table('commissions')->where('run_id', $run->id)->sum('network_cents'));
        $this->assertSame(0, DB::table('commission_chunks')->count());
    }

    public function test_the_admin_button_queues_a_run(): void
    {
        $this->post('/admin/provvigioni')->assertRedirect();
        $this->assertSame('ui', CommissionRun::sole()->triggered_by);
    }
}
