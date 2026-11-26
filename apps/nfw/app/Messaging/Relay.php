<?php

namespace App\Messaging;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Publishes pending outbox rows to Kafka, oldest first, and marks them sent
 * only after the broker acknowledges them.
 *
 * Guarantee: at-least-once. If the process dies between the broker's ack and
 * the UPDATE, the row is published again on restart; consumers deduplicate by
 * event ID. A row is never marked sent without an ack, so nothing is lost.
 */
class Relay
{
    public function __construct(private readonly Producer $producer) {}

    public function runOnce(int $batchSize = 200): RelayResult
    {
        $rows = OutboxEvent::query()
            ->pending()
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($batchSize)
            ->get();

        if ($rows->isEmpty()) {
            return new RelayResult(0, 0, null);
        }

        $messages = $rows->map(fn (OutboxEvent $row) => [
            'id' => $row->id,
            'topic' => $row->topic,
            'key' => $row->message_key,
            'body' => json_encode($row->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ])->all();

        try {
            $result = $this->producer->publish($messages);
        } catch (Throwable $e) {
            $result = ['delivered' => [], 'failed' => array_fill_keys($rows->pluck('id')->all(), $e->getMessage())];
        }

        $now = now();

        if ($result['delivered'] !== []) {
            OutboxEvent::query()->whereIn('id', $result['delivered'])->update([
                'published_at' => $now,
                'last_attempt_at' => $now,
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => null,
            ]);
        }

        $error = null;
        if ($result['failed'] !== []) {
            $error = (string) reset($result['failed']);
            OutboxEvent::query()->whereIn('id', array_keys($result['failed']))->update([
                'last_attempt_at' => $now,
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => mb_substr($error, 0, 1000),
            ]);
        }

        return new RelayResult(count($result['delivered']), count($result['failed']), $error);
    }
}
