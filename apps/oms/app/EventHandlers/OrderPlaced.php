<?php

namespace App\EventHandlers;

use App\Jobs\GenerateInvoice;
use App\Messaging\Envelope;
use App\Messaging\Outbox;
use App\Models\Shipment;

/**
 * NFW took an order: create the shipment and announce it.
 * Runs inside IdempotentConsumer's transaction, so the shipment, the
 * processed_events row and the "order.shipped" outbox row commit together.
 */
class OrderPlaced
{
    private const CARRIERS = ['Corriere Arno', 'Spedizioni Tirreno', 'Pacchi Appennino'];

    public function __construct(private readonly Outbox $outbox) {}

    public function __invoke(Envelope $event): void
    {
        $p = $event->payload;
        $carrier = self::CARRIERS[crc32($p['order_id']) % count(self::CARRIERS)];

        $shipment = Shipment::create([
            'order_uuid' => $p['order_id'],
            'seller_code' => $p['seller']['code'],
            'seller_name' => $p['seller']['name'],
            'city' => $p['seller']['city'],
            'lines' => $p['lines'],
            'gifts' => $p['gifts'] ?? [],
            'total_cents' => $p['total_cents'],
            'carrier' => $carrier,
            'tracking_code' => strtoupper(substr($carrier, 0, 2)).'-'.strtoupper(substr(str_replace('-', '', $p['order_id']), -10)),
            'status' => 'shipped',
            'shipped_at' => now(),
        ]);

        $this->outbox->record('aurora.shipments', 'order.shipped', $shipment->order_uuid, [
            'order_id' => $shipment->order_uuid,
            'carrier' => $shipment->carrier,
            'tracking_code' => $shipment->tracking_code,
            'shipped_at' => $shipment->shipped_at->toIso8601String(),
        ]);

        // Queued on Horizon only once the transaction has committed.
        GenerateInvoice::dispatch($shipment->id)->afterCommit();
    }
}
