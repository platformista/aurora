<?php

namespace App\Messaging;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Runs a handler at most once per event ID.
 *
 * The `processed_events` insert and the handler's writes (including any outbox
 * rows it records) share one transaction. A redelivered event finds its ID
 * already present and is skipped. If the handler throws, the insert rolls back
 * with everything else and the event is retried.
 */
class IdempotentConsumer
{
    /**
     * @param  callable(Envelope): void  $handler
     * @return bool true if handled now, false if it was a duplicate
     */
    public function handle(string $consumer, Envelope $event, callable $handler): bool
    {
        $handled = DB::transaction(function () use ($consumer, $event, $handler): bool {
            $inserted = DB::table('processed_events')->insertOrIgnore([
                'event_id' => $event->id,
                'consumer' => $consumer,
                'event_type' => $event->type,
                'processed_at' => now(),
            ]);

            if ($inserted === 0) {
                return false;
            }

            $handler($event);

            return true;
        });

        if (! $handled) {
            Cache::store('shared')->increment('stats:'.config('messaging.app').':duplicates_skipped');
        }

        return $handled;
    }
}
