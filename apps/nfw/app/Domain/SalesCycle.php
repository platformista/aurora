<?php

namespace App\Domain;

use Carbon\CarbonImmutable;

/**
 * Sales cycles of three weeks, numbered from 1. Commissions are calculated per cycle.
 */
final class SalesCycle
{
    public const ANCHOR = '2026-01-05';

    public const DAYS = 21;

    private function __construct(public readonly int $number) {}

    public static function current(): self
    {
        return self::containing(CarbonImmutable::now());
    }

    public static function containing(CarbonImmutable $date): self
    {
        $days = (int) CarbonImmutable::parse(self::ANCHOR)->startOfDay()->diffInDays($date->startOfDay(), false);

        return new self(max(1, intdiv($days, self::DAYS) + 1));
    }

    public static function number(int $number): self
    {
        return new self(max(1, $number));
    }

    public function previous(): self
    {
        return new self(max(1, $this->number - 1));
    }

    public function start(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::ANCHOR)->startOfDay()->addDays(($this->number - 1) * self::DAYS);
    }

    /** Exclusive end. */
    public function end(): CarbonImmutable
    {
        return $this->start()->addDays(self::DAYS);
    }

    public function label(): string
    {
        return sprintf('Ciclo %d · %s – %s', $this->number, $this->start()->format('d/m'), $this->end()->subDay()->format('d/m/Y'));
    }
}
