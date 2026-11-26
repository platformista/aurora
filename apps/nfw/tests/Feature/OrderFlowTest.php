<?php

namespace Tests\Feature;

use App\Domain\DemoData;
use App\Domain\OrderService;
use App\EventHandlers\OrderShipped;
use App\Messaging\Envelope;
use App\Messaging\IdempotentConsumer;
use App\Messaging\OutboxEvent;
use App\Models\Incaricata;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use RefreshDatabase;

    private bool $promotionsUp = true;

    protected function setUp(): void
    {
        parent::setUp();
        $data = app(DemoData::class);
        $data->products();
        $data->sellers(10);

        Http::fake(['*/api/quote' => fn () => $this->promotionsUp
            ? Http::response([
                'subtotal_cents' => 4580, 'discounts' => [['code' => 'X', 'label' => 'Sconto', 'amount_cents' => 458]],
                'gifts' => [], 'discount_cents' => 458, 'total_cents' => 4122,
            ])
            : Http::response('', 503)]);
    }

    public function test_placing_an_order_writes_the_order_and_its_event_together(): void
    {
        $this->withSession(['seller_id' => 3])
            ->post('/ordini', ['qty' => ['VIS-004' => 2]])
            ->assertRedirect('/ordini');

        $order = Order::sole();
        $this->assertSame('placed', $order->status);
        $this->assertSame(4122, $order->total_cents);
        $this->assertCount(1, $order->lines);

        $event = OutboxEvent::sole();
        $this->assertSame('aurora.orders', $event->topic);
        $this->assertSame('order.placed', $event->type);
        $this->assertSame($order->uuid, $event->message_key);
        $this->assertSame($order->uuid, $event->payload['payload']['order_id']);
        $this->assertSame('INC-000003', $event->payload['payload']['seller']['code']);
        $this->assertNull($event->published_at, 'nothing is sent to Kafka during the request');
    }

    public function test_if_the_transaction_fails_there_is_neither_order_nor_event(): void
    {
        $service = app(OrderService::class);
        $lines = $service->lines(['VIS-004' => 1]);
        DB::listen(function ($query) {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'outbox_events')) {
                throw new RuntimeException('disk full');
            }
        });

        try {
            $service->place(Incaricata::find(1), $lines, $service->quote($lines));
            $this->fail('expected an exception');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, OutboxEvent::count());
    }

    public function test_the_order_is_still_taken_when_the_broker_is_down(): void
    {
        app(\App\Messaging\BrokerSwitch::class)->set(true);

        $this->postJson('/api/ordini', ['seller_code' => 'INC-000002', 'qty' => ['COR-001' => 3]])->assertCreated();

        $this->assertSame(1, Order::count());
        $this->assertSame(1, OutboxEvent::pending()->count());
    }

    public function test_promotion_engine_down_blocks_the_order_with_a_clear_message(): void
    {
        $this->promotionsUp = false;

        $this->withSession(['seller_id' => 3])
            ->post('/ordini', ['qty' => ['VIS-004' => 1]])
            ->assertSessionHas('error');

        $this->assertSame(0, Order::count());
    }

    public function test_order_shipped_updates_the_status_once(): void
    {
        $order = Order::create([
            'uuid' => (string) Str::uuid7(), 'seller_id' => 1, 'status' => 'placed',
            'subtotal_cents' => 1000, 'total_cents' => 1000, 'placed_at' => now(),
        ]);
        $event = new Envelope((string) Str::uuid7(), 'order.shipped', 'oms', $order->uuid, now()->toIso8601String(), [
            'order_id' => $order->uuid, 'carrier' => 'Corriere Arno', 'tracking_code' => 'CO-123', 'shipped_at' => now()->toIso8601String(),
        ]);
        $consumer = app(IdempotentConsumer::class);

        $this->assertTrue($consumer->handle('shipments', $event, app(OrderShipped::class)));
        $this->assertFalse($consumer->handle('shipments', $event, app(OrderShipped::class)));

        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertSame('CO-123', $order->fresh()->tracking_code);
    }
}
