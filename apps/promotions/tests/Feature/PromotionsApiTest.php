<?php

namespace Tests\Feature;

use App\EventHandlers\OrderPlaced;
use App\Messaging\Envelope;
use App\Messaging\IdempotentConsumer;
use App\Messaging\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PromotionsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_endpoint_uses_the_seeded_rules(): void
    {
        $this->postJson('/api/quote', ['lines' => [
            ['sku' => 'PRO-002', 'category' => 'Profumi', 'qty' => 2, 'unit_price_cents' => 3990],
            ['sku' => 'VIS-004', 'category' => 'Viso', 'qty' => 1, 'unit_price_cents' => 2290],
        ]])
            ->assertOk()
            ->assertJsonPath('subtotal_cents', 10270)
            ->assertJsonPath('discounts.0.code', 'SOGLIA-80')
            ->assertJsonPath('gifts.0.sku', 'OMG-001');
    }

    public function test_quote_rejects_an_empty_basket(): void
    {
        $this->postJson('/api/quote', ['lines' => []])->assertUnprocessable();
    }

    public function test_redemptions_are_recorded_once_and_announced(): void
    {
        $orderId = (string) Str::uuid7();
        $event = new Envelope((string) Str::uuid7(), 'order.placed', 'nfw', $orderId, now()->toIso8601String(), [
            'order_id' => $orderId,
            'seller' => ['code' => 'INC-000003'],
            'promotions' => [['code' => 'SOGLIA-80', 'label' => '10%', 'amount_cents' => 798]],
            'gifts' => [['sku' => 'OMG-001', 'name' => 'Balsamo labbra mini', 'code' => 'OMAGGIO-NOTTE']],
        ]);
        $consumer = app(IdempotentConsumer::class);

        $consumer->handle('promotions', $event, app(OrderPlaced::class));
        $consumer->handle('promotions', $event, app(OrderPlaced::class));

        $this->assertSame(2, DB::table('redemptions')->count());
        $this->assertSame(['SOGLIA-80', 'OMAGGIO-NOTTE'], OutboxEvent::sole()->payload['payload']['codes']);
    }

    public function test_orders_without_promotions_produce_no_event(): void
    {
        $orderId = (string) Str::uuid7();
        $event = new Envelope((string) Str::uuid7(), 'order.placed', 'nfw', $orderId, now()->toIso8601String(), [
            'order_id' => $orderId, 'seller' => ['code' => 'INC-000003'], 'promotions' => [], 'gifts' => [],
        ]);

        app(IdempotentConsumer::class)->handle('promotions', $event, app(OrderPlaced::class));

        $this->assertSame(0, OutboxEvent::count());
        $this->assertSame(1, DB::table('processed_events')->count());
    }
}
