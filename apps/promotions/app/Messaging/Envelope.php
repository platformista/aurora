<?php

namespace App\Messaging;

use InvalidArgumentException;

/**
 * The message format shared by all three apps. The event ID is generated once,
 * when the outbox row is written, and travels unchanged through every retry
 * and redelivery: it is what makes consumers idempotent.
 */
final class Envelope
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $source,
        public readonly string $key,
        public readonly string $occurredAt,
        public readonly array $payload,
    ) {}

    public static function make(string $id, string $type, string $key, array $payload): self
    {
        return new self($id, $type, (string) config('messaging.app'), $key, now()->toIso8601ZuluString('microsecond'), $payload);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        foreach (['id', 'type', 'source', 'key', 'occurred_at', 'payload'] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidArgumentException("Envelope is missing '{$field}'.");
            }
        }

        return new self($data['id'], $data['type'], $data['source'], $data['key'], $data['occurred_at'], $data['payload']);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'source' => $this->source,
            'key' => $this->key,
            'occurred_at' => $this->occurredAt,
            'payload' => $this->payload,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
