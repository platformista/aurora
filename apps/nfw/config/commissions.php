<?php

return [
    // Orders per parallel job.
    'chunk_size' => (int) env('COMMISSION_CHUNK_SIZE', 10_000),

    // Demo calibration, see App\Domain\CommissionCalculator. Tune it so that a
    // run on the seeded data takes about a minute on the smallest worker.
    'complexity' => (int) env('COMMISSION_COMPLEXITY', 200),
];
