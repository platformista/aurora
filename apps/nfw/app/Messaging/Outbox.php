<?php

namespace App\Messaging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Transactional outbox.
 *
 * The business change and the event that announces it are written in the same
 * database transaction. If the transaction commits, the event exists and the
 * relay will publish it eventually; if it rolls back, neither exists. Nothing
 * here talks to Kafka, so a broker outage cannot fail or lose a business write.
 */
class Outbox
{
    /**
     * @param  int  $ambientTransactionLevel  Transactions already open around the caller that do
     *                                        not count (tests that run inside a wrapping transaction).
     */
    public function __construct(private readonly int $ambientTransactionLevel = 0) {}

    public function record(string $topic, string $type, string $key, array $payload): OutboxEvent
    {
        if (DB::transactionLevel() <= $this->ambientTransactionLevel) {
            throw new LogicException(
                'Outbox::record() must be called inside a database transaction, '
                .'together with the business change it describes.'
            );
        }

        $id = (string) Str::uuid7();

        return OutboxEvent::create([
            'id' => $id,
            'topic' => $topic,
            'type' => $type,
            'message_key' => $key,
            'payload' => Envelope::make($id, $type, $key, $payload)->toArray(),
            'attempts' => 0,
        ]);
    }
}
