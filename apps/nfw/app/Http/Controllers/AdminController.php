<?php

namespace App\Http\Controllers;

use App\Domain\CommissionRunner;
use App\Domain\SalesCycle;
use App\Messaging\BrokerSwitch;
use App\Messaging\OutboxEvent;
use App\Messaging\Stats;
use App\Models\CommissionRun;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Throwable;

class AdminController extends Controller
{
    public function show()
    {
        return Inertia::render('Admin', ['cycle' => SalesCycle::current()->label()]);
    }

    public function stats(Stats $stats, BrokerSwitch $switch)
    {
        $remote = function (string $service) {
            try {
                return Http::baseUrl((string) config("services.{$service}.url"))->timeout(2)->get('/internal/stats')->throw()->json();
            } catch (Throwable $e) {
                return ['app' => $service, 'unreachable' => true];
            }
        };

        return response()->json([
            'broker_down' => $switch->isSimulatingOutage(),
            'apps' => [
                $stats->snapshot() + [
                    // Orders taken during the demo (each has exactly one order.placed event).
                    'orders_sent' => OutboxEvent::query()->where('type', 'order.placed')->count(),
                    'orders_awaiting_shipment' => Order::query()->where('status', 'placed')->count(),
                ],
                $remote('oms'),
                $remote('promotions'),
            ],
            'runs' => CommissionRun::query()->orderByDesc('id')->limit(8)->get()->map->summary(),
            'at' => now()->toIso8601String(),
        ]);
    }

    public function broker(Request $request, BrokerSwitch $switch)
    {
        $down = $request->boolean('down');
        $switch->set($down);

        return back()->with('success', $down
            ? 'Simulazione attiva: i relay non raggiungono più il broker.'
            : 'Simulazione disattivata: i relay si riconnettono e svuotano la outbox.');
    }

    public function commissions(Request $request, CommissionRunner $runner)
    {
        $cycle = $request->input('cycle') === 'previous' ? SalesCycle::current()->previous() : SalesCycle::current();
        $run = $runner->start($cycle, 'ui');

        return back()->with('success', "Calcolo provvigioni #{$run->id} accodato sulla coda «commissions».");
    }
}
