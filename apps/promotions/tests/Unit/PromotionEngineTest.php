<?php

namespace Tests\Unit;

use App\Domain\PromotionEngine;
use PHPUnit\Framework\TestCase;

class PromotionEngineTest extends TestCase
{
    private function engine(): PromotionEngine
    {
        return new PromotionEngine([
            ['code' => 'SOGLIA-80', 'label' => '10% sopra 80 €', 'type' => 'percent_over_threshold', 'params' => ['threshold_cents' => 8000, 'percent' => 10]],
            ['code' => 'OMAGGIO-NOTTE', 'label' => 'Omaggio', 'type' => 'gift_with_product', 'params' => ['trigger_sku' => 'VIS-004', 'gift_sku' => 'OMG-001', 'gift_name' => 'Balsamo labbra mini']],
            ['code' => 'TRIO-CORPO', 'label' => '15% Corpo', 'type' => 'category_quantity', 'params' => ['category' => 'Corpo', 'min_qty' => 3, 'percent' => 15]],
        ]);
    }

    public function test_no_promotion_below_the_thresholds(): void
    {
        $q = $this->engine()->quote([['sku' => 'COR-001', 'category' => 'Corpo', 'qty' => 2, 'unit_price_cents' => 1290]]);

        $this->assertSame(2580, $q['subtotal_cents']);
        $this->assertSame([], $q['discounts']);
        $this->assertSame(2580, $q['total_cents']);
    }

    public function test_threshold_discount(): void
    {
        $q = $this->engine()->quote([['sku' => 'PRO-002', 'category' => 'Profumi', 'qty' => 3, 'unit_price_cents' => 3990]]);

        $this->assertSame(['SOGLIA-80'], array_column($q['discounts'], 'code'));
        $this->assertSame(1197, $q['discount_cents']);
        $this->assertSame(10773, $q['total_cents']);
    }

    public function test_gift_with_product(): void
    {
        $q = $this->engine()->quote([['sku' => 'VIS-004', 'category' => 'Viso', 'qty' => 1, 'unit_price_cents' => 2290]]);

        $this->assertSame('OMG-001', $q['gifts'][0]['sku']);
        $this->assertSame(2290, $q['total_cents']);
    }

    public function test_category_and_threshold_discounts_add_up(): void
    {
        // 3 Corpo items: 3 x 19,50 = 58,50 -> 15% = 8,77; plus 2 x 22,90 = 45,80; subtotal 104,30
        $q = $this->engine()->quote([
            ['sku' => 'COR-004', 'category' => 'Corpo', 'qty' => 3, 'unit_price_cents' => 1950],
            ['sku' => 'VIS-004', 'category' => 'Viso', 'qty' => 2, 'unit_price_cents' => 2290],
        ]);

        $this->assertSame(10430, $q['subtotal_cents']);
        $this->assertSame(['TRIO-CORPO', 'SOGLIA-80'], array_column($q['discounts'], 'code'));
        $this->assertSame(877, $q['discounts'][0]['amount_cents']);
        $this->assertSame(955, $q['discounts'][1]['amount_cents']);   // 10% of (104,30 - 8,77)
        $this->assertSame(10430 - 877 - 955, $q['total_cents']);
        $this->assertCount(1, $q['gifts']);
    }
}
