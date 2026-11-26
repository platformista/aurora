<?php

namespace App\Domain;

use App\Messaging\Outbox;
use App\Models\Incaricata;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OrderService
{
    public function __construct(
        private readonly PromotionsClient $promotions,
        private readonly Outbox $outbox,
    ) {}

    /**
     * @param  array<string, int>  $quantities  sku => qty
     * @return list<array{sku: string, name: string, category: string, qty: int, unit_price_cents: int}>
     */
    public function lines(array $quantities): array
    {
        $quantities = array_filter(array_map('intval', $quantities), fn (int $q) => $q > 0);
        if ($quantities === []) {
            throw new InvalidArgumentException('Il carrello è vuoto.');
        }

        return Product::query()->whereIn('sku', array_keys($quantities))->orderBy('sku')->get()
            ->map(fn (Product $p) => [
                'sku' => $p->sku,
                'name' => $p->name,
                'category' => $p->category,
                'qty' => min(99, $quantities[$p->sku]),
                'unit_price_cents' => $p->price_cents,
            ])->values()->all();
    }

    /** Synchronous price check against the Promotion Engine. */
    public function quote(array $lines): array
    {
        return $this->promotions->quote($lines);
    }

    /**
     * Saves the order and its "order.placed" event in one transaction.
     * No call to Kafka happens here: if the broker is down, the order is still taken.
     */
    public function place(Incaricata $seller, array $lines, array $quote): Order
    {
        return DB::transaction(function () use ($seller, $lines, $quote) {
            $order = Order::create([
                'uuid' => (string) Str::uuid7(),
                'seller_id' => $seller->id,
                'status' => 'placed',
                'subtotal_cents' => $quote['subtotal_cents'],
                'discount_cents' => $quote['discount_cents'],
                'total_cents' => $quote['total_cents'],
                'promotions' => $quote['discounts'],
                'gifts' => $quote['gifts'],
                'placed_at' => now(),
            ]);

            $order->lines()->createMany(array_map(fn ($l) => [
                'sku' => $l['sku'], 'name' => $l['name'], 'qty' => $l['qty'], 'unit_price_cents' => $l['unit_price_cents'],
            ], $lines));

            $this->outbox->record('aurora.orders', 'order.placed', $order->uuid, [
                'order_id' => $order->uuid,
                'seller' => $seller->summary(),
                'lines' => $lines,
                'subtotal_cents' => $order->subtotal_cents,
                'discount_cents' => $order->discount_cents,
                'total_cents' => $order->total_cents,
                'promotions' => $quote['discounts'],
                'gifts' => $quote['gifts'],
                'placed_at' => $order->placed_at->toIso8601String(),
            ]);

            return $order;
        });
    }
}
