<?php

namespace Tests\Unit;

use App\Domain\CommissionCalculator;
use App\Domain\SalesCycle;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class CommissionCalculatorTest extends TestCase
{
    public function test_pays_personal_and_five_active_levels_with_dynamic_compression(): void
    {
        // Chain: 7 -> 6 -> 5 -> 4 -> 3 -> 2 -> 1 (1 is the top). 4 is inactive.
        $uplines = [1 => 0, 2 => 1, 3 => 2, 4 => 3, 5 => 4, 6 => 5, 7 => 6];
        $active = [1 => true, 2 => true, 3 => true, 5 => true, 6 => true];

        $result = (new CommissionCalculator($uplines, $active))->compute([[7, 10_000]]);

        $this->assertSame([7 => 1_000], $result['personal']);              // 10% to the seller
        // Levels: 6 (6%), 5 (4%), 4 skipped, 3 (3%), 2 (2%), 1 (1%)
        $this->assertSame([6 => 600, 5 => 400, 3 => 300, 2 => 200, 1 => 100], $result['network']);
    }

    public function test_stops_after_five_paid_levels(): void
    {
        $uplines = [1 => 0, 2 => 1, 3 => 2, 4 => 3, 5 => 4, 6 => 5, 7 => 6];
        $active = array_fill_keys(range(1, 7), true);

        $result = (new CommissionCalculator($uplines, $active))->compute([[7, 10_000]]);

        $this->assertArrayNotHasKey(1, $result['network']);
        $this->assertSame(1_600, array_sum($result['network']));   // 6+4+3+2+1 % of 100 €
    }

    public function test_complexity_does_not_change_the_result(): void
    {
        $uplines = [1 => 0, 2 => 1, 3 => 2];
        $active = [1 => true, 2 => true];
        $orders = [[3, 5_000], [2, 7_000], [3, 1_234]];

        $this->assertSame(
            (new CommissionCalculator($uplines, $active, 1))->compute($orders),
            (new CommissionCalculator($uplines, $active, 25))->compute($orders),
        );
    }

    public function test_sales_cycles_are_three_weeks_long(): void
    {
        $this->assertSame(1, SalesCycle::containing(CarbonImmutable::parse('2026-01-05'))->number);
        $this->assertSame(1, SalesCycle::containing(CarbonImmutable::parse('2026-01-25'))->number);
        $this->assertSame(2, SalesCycle::containing(CarbonImmutable::parse('2026-01-26'))->number);
        $this->assertSame('2026-01-26', SalesCycle::number(2)->start()->toDateString());
        $this->assertSame('2026-02-16', SalesCycle::number(2)->end()->toDateString());
    }
}
