<?php

namespace App\Messaging;

interface Producer
{
    /**
     * Publish a batch of messages and wait for the broker to acknowledge them.
     *
     * @param  list<array{id: string, topic: string, key: string, body: string}>  $messages
     * @return array{delivered: list<string>, failed: array<string, string>}
     *                                                                       IDs acknowledged by the broker, and IDs that were not, with the reason.
     */
    public function publish(array $messages): array;
}
