<?php

namespace Tests\Messaging;

use App\Messaging\Backoff;
use App\Messaging\BrokerSwitch;
use App\Messaging\Envelope;
use App\Messaging\IdempotentConsumer;
use App\Messaging\Outbox;
use App\Messaging\OutboxEvent;
use App\Messaging\Relay;
use App\Messaging\Stats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Identical in all three apps (CI checks it), like app/Messaging itself.
 */
class MessagingTest extends TestCase
{
    use RefreshDatabase;

    private function record(string $key = 'k1', array $payload = ['n' => 1]): OutboxEvent
    {
        return DB::transaction(fn () => app(Outbox::class)->record('aurora.test', 'test.happened', $key, $payload));
    }

    public function test_outbox_refuses_to_write_outside_a_transaction(): void
    {
        // RefreshDatabase wraps each test in a transaction; that one does not count.
        $outbox = new Outbox(ambientTransactionLevel: DB::transactionLevel());

        $this->expectException(LogicException::class);
        $outbox->record('aurora.test', 'test.happened', 'k', []);
    }

    public function test_outbox_row_commits_and_rolls_back_with_the_business_change(): void
    {
        $this->record();
        $this->assertSame(1, OutboxEvent::count());

        try {
            DB::transaction(function () {
                app(Outbox::class)->record('aurora.test', 'test.happened', 'k2', []);
                throw new RuntimeException('business rule failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(1, OutboxEvent::count(), 'a rolled-back change must leave no event behind');
    }

    public function test_the_envelope_carries_the_row_id_as_event_id(): void
    {
        $row = $this->record('order-1', ['total' => 10]);

        $envelope = Envelope::fromJson(json_encode($row->payload));
        $this->assertSame($row->id, $envelope->id);
        $this->assertSame('test.happened', $envelope->type);
        $this->assertSame('order-1', $envelope->key);
        $this->assertSame(['total' => 10], $envelope->payload);
        $this->assertSame(config('messaging.app'), $envelope->source);
    }

    public function test_relay_publishes_pending_events_in_order_and_marks_them_sent(): void
    {
        $a = $this->record('a');
        $this->travel(1)->seconds();
        $b = $this->record('b');

        $producer = new FakeProducer;
        $result = (new Relay($producer))->runOnce();

        $this->assertSame(2, $result->published);
        $this->assertSame([$a->id, $b->id], array_column($producer->sent, 'id'));
        $this->assertSame('a', $producer->sent[0]['key']);
        $this->assertSame(0, OutboxEvent::pending()->count());
        $this->assertTrue((new Relay($producer))->runOnce()->idle());
    }

    public function test_relay_keeps_events_when_the_broker_is_unreachable_and_drains_later(): void
    {
        $this->record('a');
        $this->record('b');
        $producer = new FakeProducer;

        $producer->fail = 'all';
        $result = (new Relay($producer))->runOnce();
        $this->assertSame(2, $result->failed);
        $this->assertSame(2, OutboxEvent::pending()->count());
        $this->assertSame(1, OutboxEvent::first()->attempts);
        $this->assertStringContainsString('timed out', OutboxEvent::first()->last_error);

        $producer->fail = 'throw';
        $result = (new Relay($producer))->runOnce();
        $this->assertSame(2, $result->failed);
        $this->assertSame('Connection refused', $result->error);
        $this->assertSame(2, OutboxEvent::pending()->count());

        $producer->fail = 'none';
        $result = (new Relay($producer))->runOnce();
        $this->assertSame(2, $result->published);
        $this->assertSame(0, OutboxEvent::pending()->count());
        $this->assertNull(OutboxEvent::first()->last_error);
        $this->assertSame(3, OutboxEvent::first()->attempts);
    }

    public function test_relay_marks_only_the_acknowledged_events(): void
    {
        $a = $this->record('a');
        $b = $this->record('b');
        $producer = new FakeProducer;
        $producer->fail = [$b->id];

        $result = (new Relay($producer))->runOnce();

        $this->assertSame(1, $result->published);
        $this->assertSame(1, $result->failed);
        $this->assertNotNull($a->fresh()->published_at);
        $this->assertNull($b->fresh()->published_at);
    }

    public function test_backoff_doubles_and_is_capped(): void
    {
        $this->assertSame([0, 1, 2, 4, 8, 16, 30, 30], array_map(fn ($n) => Backoff::seconds($n), range(0, 7)));
    }

    public function test_consumer_handles_an_event_once_even_if_delivered_twice(): void
    {
        $event = new Envelope((string) Str::uuid7(), 'test.happened', 'other-app', 'k', now()->toIso8601String(), []);
        $calls = 0;
        $consumer = app(IdempotentConsumer::class);

        $this->assertTrue($consumer->handle('test', $event, function () use (&$calls) { $calls++; }));
        $this->assertFalse($consumer->handle('test', $event, function () use (&$calls) { $calls++; }));

        $this->assertSame(1, $calls);
        $this->assertSame(1, DB::table('processed_events')->count());
        $this->assertSame(1, app(Stats::class)->snapshot()['duplicates_skipped']);
    }

    public function test_a_failed_handler_leaves_no_trace_and_the_event_can_be_retried(): void
    {
        $event = new Envelope((string) Str::uuid7(), 'test.happened', 'other-app', 'k', now()->toIso8601String(), []);
        $consumer = app(IdempotentConsumer::class);

        try {
            $consumer->handle('test', $event, function () {
                app(Outbox::class)->record('aurora.test', 'test.reacted', 'k', []);
                throw new RuntimeException('database hiccup');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DB::table('processed_events')->count());
        $this->assertSame(0, OutboxEvent::count(), 'the reaction event rolls back with the handler');

        $this->assertTrue($consumer->handle('test', $event, fn () => app(Outbox::class)->record('aurora.test', 'test.reacted', 'k', [])));
        $this->assertSame(1, OutboxEvent::count());
    }

    public function test_broker_switch_points_the_relays_at_an_unreachable_address(): void
    {
        $switch = app(BrokerSwitch::class);
        $this->assertSame(config('messaging.brokers'), $switch->brokers());

        $switch->set(true);
        $this->assertTrue($switch->isSimulatingOutage());
        $this->assertSame(BrokerSwitch::UNREACHABLE, $switch->brokers());

        $switch->set(false);
        $this->assertSame(config('messaging.brokers'), $switch->brokers());
    }

    public function test_stats_report_the_backlog(): void
    {
        $this->record('a');
        $this->record('b');
        $stats = app(Stats::class)->snapshot();

        $this->assertSame(2, $stats['outbox_pending']);
        $this->assertSame(0, $stats['outbox_published']);
        $this->assertSame(config('messaging.app'), $stats['app']);
    }
}
