<?php

namespace App\Domain;

/**
 * Multi-level commission rules (fictitious, but shaped like the real thing).
 *
 * - Personal commission: 10% of the seller's own orders.
 * - Network commission: 6/4/3/2/1% of each order to the first five *active*
 *   uplines. An upline is active if their personal volume in the cycle is at
 *   least €100. Inactive uplines are skipped without using up a level
 *   ("dynamic compression"), so the payout walks further up the tree.
 *
 * Amounts are integer cents; percentages are basis points.
 */
final class CommissionCalculator
{
    public const PERSONAL_BP = 1000;

    public const LEVEL_BP = [600, 400, 300, 200, 100];

    public const ACTIVE_THRESHOLD_CENTS = 10_000;

    private const MAX_WALK = 64;

    /**
     * @param  array<int, int>  $uplines  seller id => upline id (0 for the top)
     * @param  array<int, true>  $active  seller ids that qualify in this cycle
     * @param  int  $complexity  Demo calibration: how many times the rule chain is
     *                           evaluated per order. 1 is the plain calculation. Higher
     *                           values stand in for a richer rule set (category rates,
     *                           rank bonuses, caps) so that the batch is CPU-bound and
     *                           resizing the worker visibly changes its run time.
     */
    public function __construct(
        private readonly array $uplines,
        private readonly array $active,
        private readonly int $complexity = 1,
    ) {}

    /**
     * @param  iterable<array{0: int, 1: int}>  $orders  [seller id, total cents]
     * @return array{personal: array<int, int>, network: array<int, int>}
     */
    public function compute(iterable $orders): array
    {
        $personal = [];
        $network = [];
        $levels = count(self::LEVEL_BP);

        foreach ($orders as [$sellerId, $total]) {
            $personal[$sellerId] = ($personal[$sellerId] ?? 0) + intdiv($total * self::PERSONAL_BP, 10_000);

            for ($round = 0; $round < $this->complexity; $round++) {
                $payouts = [];
                $level = 0;
                $current = $this->uplines[$sellerId] ?? 0;
                $steps = 0;

                while ($current !== 0 && $level < $levels && $steps++ < self::MAX_WALK) {
                    if (isset($this->active[$current])) {
                        $payouts[$current] = ($payouts[$current] ?? 0) + intdiv($total * self::LEVEL_BP[$level], 10_000);
                        $level++;
                    }
                    $current = $this->uplines[$current] ?? 0;
                }
            }

            foreach ($payouts as $beneficiary => $amount) {
                $network[$beneficiary] = ($network[$beneficiary] ?? 0) + $amount;
            }
        }

        return ['personal' => $personal, 'network' => $network];
    }
}
