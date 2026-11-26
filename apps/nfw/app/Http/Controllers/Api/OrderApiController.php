<?php

namespace App\Http\Controllers\Api;

use App\Domain\OrderService;
use App\Http\Controllers\Controller;
use App\Models\Incaricata;
use Illuminate\Http\Request;

/** JSON endpoint used by the k6 peak-evening script. Same code path as the order form. */
class OrderApiController extends Controller
{
    public function store(Request $request, OrderService $orders)
    {
        $token = config('app.load_test_token');
        abort_if($token && ! hash_equals($token, (string) $request->header('X-Load-Test-Token')), 403);

        $data = $request->validate([
            'seller_code' => ['required', 'string'],
            'qty' => ['required', 'array'],
        ]);

        $seller = Incaricata::query()->where('code', $data['seller_code'])->firstOrFail();
        $lines = $orders->lines($data['qty']);
        $order = $orders->place($seller, $lines, $orders->quote($lines));

        return response()->json(['order_id' => $order->uuid, 'total_cents' => $order->total_cents], 201);
    }
}
