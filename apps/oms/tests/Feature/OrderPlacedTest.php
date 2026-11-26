<?php

namespace Tests\Feature;

use App\EventHandlers\OrderPlaced;
use App\Messaging\Envelope;
use App\Messaging\IdempotentConsumer;
use App\Messaging\OutboxEvent;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderPlacedTest extends TestCase
{
    use RefreshDatabase;

    private function event(): Envelope
    {
        $orderId = (string) Str::uuid7();

        return new Envelope((string) Str::uuid7(), 'order.placed', 'nfw', $orderId, now()->toIso8601String(), [
            'order_id' => $orderId,
            'seller' => ['id' => 3, 'code' => 'INC-000003', 'name' => 'Giulia Bianchi', 'city' => 'Pontedera', 'province' => 'PI'],
            'lines' => [['sku' => 'VIS-004', 'name' => 'Crema notte rigenerante', 'category' => 'Viso', 'qty' => 2, 'unit_price_cents' => 2290]],
            'subtotal_cents' => 4580, 'discount_cents' => 0, 'total_cents' => 4580,
            'promotions' => [], 'gifts' => [['sku' => 'OMG-001', 'name' => 'Balsamo labbra mini', 'code' => 'OMAGGIO-NOTTE']],
            'placed_at' => now()->toIso8601String(),
        ]);
    }

    public function test_one_shipment_one_event_one_invoice_even_when_delivered_twice(): void
    {
        $event = $this->event();
        $consumer = app(IdempotentConsumer::class);

        $this->assertTrue($consumer->handle('orders', $event, app(OrderPlaced::class)));
        $this->assertFalse($consumer->handle('orders', $event, app(OrderPlaced::class)));

        $shipment = Shipment::sole();
        $this->assertSame($event->payload['order_id'], $shipment->order_uuid);

        $out = OutboxEvent::sole();
        $this->assertSame('order.shipped', $out->type);
        $this->assertSame('aurora.shipments', $out->topic);
        $this->assertSame($shipment->tracking_code, $out->payload['payload']['tracking_code']);

        // The invoice job ran after commit (sync queue in tests) and stored the PDF in the database.
        $invoice = DB::table('invoices')->sole();
        $this->assertSame('database', $invoice->storage);
        $this->assertStringStartsWith('%PDF', $invoice->pdf);
    }

    public function test_the_invoice_can_be_fetched_over_the_internal_endpoint(): void
    {
        $event = $this->event();
        app(IdempotentConsumer::class)->handle('orders', $event, app(OrderPlaced::class));

        $this->get('/internal/invoices/'.$event->payload['order_id'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/internal/invoices/'.Str::uuid7())->assertNotFound();
        $this->getJson('/internal/stats')->assertJson(['app' => 'oms', 'shipments' => 1, 'invoices' => 1, 'outbox_pending' => 1]);
    }

    public function test_the_handler_is_idempotent_on_its_own(): void
    {
        $event = $this->event();
        $handler = app(OrderPlaced::class);

        DB::transaction(fn () => $handler($event));
        DB::transaction(fn () => $handler($event));   // bypassing processed_events on purpose

        $this->assertSame(1, Shipment::count());
        $this->assertSame(1, OutboxEvent::count());
    }
}
