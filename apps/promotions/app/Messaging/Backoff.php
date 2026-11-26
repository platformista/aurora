<?php

namespace App\Messaging;

/**
 * Exponential backoff for the relay: 1, 2, 4, 8, 16, 30, 30... seconds.
 * The relay backs off as a whole rather than per row, so events keep their
 * original order once the broker is reachable again.
 */
final class Backoff
{
    public static function seconds(int $consecutiveFailures, int $cap = 30): int
    {
        if ($consecutiveFailures <= 0) {
            return 0;
        }

        return (int) min($cap, 2 ** ($consecutiveFailures - 1));
    }
}
