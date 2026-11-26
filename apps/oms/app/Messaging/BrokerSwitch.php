<?php

namespace App\Messaging;

use Illuminate\Support\Facades\Cache;

/**
 * Demo switch: "Simula broker non disponibile".
 *
 * Stored in the Valkey instance all three apps share, so one click affects
 * every relay. When it is on, the relays connect to an address where nothing
 * listens, so librdkafka fails for real (connection refused, delivery timeout)
 * rather than the code skipping the publish. The managed Kafka service itself
 * keeps running: you cannot stop a managed service on demand.
 */
class BrokerSwitch
{
    public const KEY = 'simulate_broker_down';

    public const UNREACHABLE = '127.0.0.1:1';

    public function isSimulatingOutage(): bool
    {
        return (bool) Cache::store('shared')->get(self::KEY, false);
    }

    public function set(bool $down): void
    {
        Cache::store('shared')->forever(self::KEY, $down);
    }

    public function brokers(): string
    {
        return $this->isSimulatingOutage() ? self::UNREACHABLE : (string) config('messaging.brokers');
    }
}
