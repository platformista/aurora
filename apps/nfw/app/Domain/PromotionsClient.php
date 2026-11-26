<?php

namespace App\Domain;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Synchronous call to the Promotion Engine over the project's internal
 * network (http://promotions.internal on Upsun, via an app relationship).
 */
class PromotionsClient
{
    /**
     * @param  list<array{sku: string, name: string, category: string, qty: int, unit_price_cents: int}>  $lines
     */
    public function quote(array $lines): array
    {
        try {
            $response = Http::baseUrl((string) config('services.promotions.url'))
                ->timeout(3)
                ->acceptJson()
                ->post('/api/quote', ['lines' => $lines]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Motore promozioni non raggiungibile: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Motore promozioni: risposta '.$response->status());
        }

        return $response->json();
    }
}
