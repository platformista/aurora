<?php

namespace App\EventHandlers;

use App\Messaging\Envelope;
use App\Messaging\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * Records which promotions an order used (budget tracking, reporting) and
 * publishes "promotion.redeemed". Runs inside IdempotentConsumer's transaction.
 */
class OrderPlaced
{
    public function __construct(private readonly Outbox $outbox) {}

    public function __invoke(Envelope $event): void
    {
        $p = $event->payload;
        $used = array_merge(
            array_map(fn ($d) => ['code' => $d['code'], 'discount_cents' => $d['amount_cents']], $p['promotions'] ?? []),
            array_map(fn ($g) => ['code' => $g['code'], 'discount_cents' => 0], $p['gifts'] ?? []),
        );

        if ($used === []) {
            return;
        }

        DB::table('redemptions')->insert(array_map(fn ($u) => [
            'order_uuid' => $p['order_id'],
            'rule_code' => $u['code'],
            'discount_cents' => $u['discount_cents'],
            'seller_code' => $p['seller']['code'],
            'redeemed_at' => now(),
        ], $used));

        $this->outbox->record('aurora.promotions', 'promotion.redeemed', $p['order_id'], [
            'order_id' => $p['order_id'],
            'codes' => array_column($used, 'code'),
            'discount_cents' => array_sum(array_column($used, 'discount_cents')),
        ]);
    }
}
