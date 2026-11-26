<?php

namespace App\Http\Controllers;

use App\Domain\OrderService;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Throwable;

class OrderController extends Controller
{
    public function create()
    {
        return Inertia::render('Ordini/Nuovo', [
            'products' => Product::query()->orderBy('category')->orderBy('name')->get(['sku', 'name', 'category', 'price_cents']),
        ]);
    }

    public function quote(Request $request, OrderService $orders)
    {
        $data = $request->validate(['qty' => ['required', 'array']]);

        try {
            $lines = $orders->lines($data['qty']);

            return response()->json(['lines' => $lines, 'quote' => $orders->quote($lines)]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function store(Request $request, OrderService $orders)
    {
        $data = $request->validate(['qty' => ['required', 'array']]);

        try {
            $lines = $orders->lines($data['qty']);
            $order = $orders->place($request->attributes->get('seller'), $lines, $orders->quote($lines));
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('orders.index')->with('success', 'Ordine '.substr($order->uuid, -8).' registrato.');
    }

    public function index(Request $request)
    {
        $seller = $request->attributes->get('seller');

        return Inertia::render('Ordini/Elenco', [
            'orders' => Order::query()
                ->where('seller_id', $seller->id)
                ->with('lines')
                ->orderByDesc('id')
                ->limit(30)
                ->get()
                ->map(fn (Order $o) => [
                    'uuid' => $o->uuid,
                    'short' => substr($o->uuid, -8),
                    'status' => $o->status,
                    'total_cents' => $o->total_cents,
                    'discount_cents' => $o->discount_cents,
                    'promotions' => $o->promotions ?? [],
                    'gifts' => $o->gifts ?? [],
                    'lines' => $o->lines->map->only(['sku', 'name', 'qty', 'unit_price_cents']),
                    'carrier' => $o->carrier,
                    'tracking_code' => $o->tracking_code,
                    'placed_at' => $o->placed_at?->toIso8601String(),
                    'shipped_at' => $o->shipped_at?->toIso8601String(),
                ]),
        ]);
    }

    /** The PDF lives in the OMS (object storage on Upsun); fetch it over the internal network. */
    public function invoice(Request $request, string $uuid)
    {
        $order = Order::query()->where('uuid', $uuid)->where('seller_id', $request->attributes->get('seller')->id)->firstOrFail();
        $response = Http::baseUrl((string) config('services.oms.url'))->timeout(5)->get("/internal/invoices/{$order->uuid}");

        abort_unless($response->successful(), 404, 'Fattura non ancora disponibile.');

        return response($response->body(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="fattura-'.substr($order->uuid, -8).'.pdf"',
        ]);
    }
}
