<?php

namespace App\EventHandlers;

use App\Messaging\Envelope;
use App\Models\Order;
use Illuminate\Support\Carbon;

/** OMS has shipped the order: update its status in the seller portal. */
class OrderShipped
{
    public function __invoke(Envelope $event): void
    {
        Order::query()->where('uuid', $event->payload['order_id'])->update([
            'status' => 'shipped',
            'carrier' => $event->payload['carrier'],
            'tracking_code' => $event->payload['tracking_code'],
            'shipped_at' => Carbon::parse($event->payload['shipped_at']),
        ]);
    }
}
