<?php

namespace App\Domain;

/**
 * Prices a basket. Pure function of the basket and the rules: no state, no I/O,
 * so it is fast enough to be called synchronously on every order.
 *
 * Rules are evaluated independently on the list prices and their discounts add up:
 * - category_quantity: N% off the lines of a category when at least min_qty items are in it;
 * - percent_over_threshold: N% off what remains when the subtotal reaches the threshold;
 * - gift_with_product: a free item when the trigger product is in the basket.
 */
class PromotionEngine
{
    /**
     * @param  list<array{code: string, label: string, type: string, params: array}>  $rules
     */
    public function __construct(private readonly array $rules) {}

    /**
     * @param  list<array{sku: string, name?: string, category: string, qty: int, unit_price_cents: int}>  $lines
     */
    public function quote(array $lines): array
    {
        $subtotal = array_sum(array_map(fn ($l) => $l['qty'] * $l['unit_price_cents'], $lines));
        $discounts = [];
        $gifts = [];

        foreach ($this->byType('category_quantity') as $rule) {
            $inCategory = array_filter($lines, fn ($l) => $l['category'] === $rule['params']['category']);
            $qty = array_sum(array_column($inCategory, 'qty'));
            if ($qty >= $rule['params']['min_qty']) {
                $base = array_sum(array_map(fn ($l) => $l['qty'] * $l['unit_price_cents'], $inCategory));
                $discounts[] = $this->discount($rule, intdiv($base * $rule['params']['percent'], 100));
            }
        }

        foreach ($this->byType('percent_over_threshold') as $rule) {
            if ($subtotal >= $rule['params']['threshold_cents']) {
                $already = array_sum(array_column($discounts, 'amount_cents'));
                $discounts[] = $this->discount($rule, intdiv(($subtotal - $already) * $rule['params']['percent'], 100));
            }
        }

        foreach ($this->byType('gift_with_product') as $rule) {
            if (in_array($rule['params']['trigger_sku'], array_column($lines, 'sku'), true)) {
                $gifts[] = ['sku' => $rule['params']['gift_sku'], 'name' => $rule['params']['gift_name'], 'code' => $rule['code']];
            }
        }

        $discount = array_sum(array_column($discounts, 'amount_cents'));

        return [
            'subtotal_cents' => $subtotal,
            'discounts' => $discounts,
            'gifts' => $gifts,
            'discount_cents' => $discount,
            'total_cents' => max(0, $subtotal - $discount),
        ];
    }

    private function byType(string $type): array
    {
        return array_values(array_filter($this->rules, fn ($r) => $r['type'] === $type));
    }

    private function discount(array $rule, int $amount): array
    {
        return ['code' => $rule['code'], 'label' => $rule['label'], 'amount_cents' => $amount];
    }
}
