<?php

namespace App\Messaging\Console;

use App\Messaging\Backoff;
use App\Messaging\Relay;
use Illuminate\Console\Command;

/**
 * Long-running relay. Runs as its own Upsun worker; if it exits, Upsun
 * restarts it. On SIGTERM (deploys) it finishes the current batch and stops.
 */
class RelayCommand extends Command
{
    protected $signature = 'outbox:relay {--once : Process one batch and exit} {--batch=200}';

    protected $description = 'Publish pending outbox events to Kafka';

    private bool $stopping = false;

    public function handle(Relay $relay): int
    {
        $this->trapSignals();
        $failures = 0;

        do {
            $result = $relay->runOnce((int) $this->option('batch'));

            if ($result->failed > 0) {
                $failures++;
                $wait = Backoff::seconds($failures);
                $this->components->warn("Broker unreachable ({$result->failed} events kept in the outbox, retry in {$wait}s): {$result->error}");
                $this->sleepFor($wait);

                continue;
            }

            if ($failures > 0 && $result->published > 0) {
                $this->components->info('Broker reachable again, draining the outbox.');
            }
            $failures = 0;

            if ($result->published > 0) {
                $this->line(now()->toTimeString()." published {$result->published}");
            } elseif (! $this->option('once')) {
                usleep(300_000);
            }
        } while (! $this->option('once') && ! $this->stopping);

        return self::SUCCESS;
    }

    private function sleepFor(int $seconds): void
    {
        for ($i = 0; $i < $seconds * 10 && ! $this->stopping && ! $this->option('once'); $i++) {
            usleep(100_000);
        }
    }

    private function trapSignals(): void
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stopping = true);
            pcntl_signal(SIGINT, fn () => $this->stopping = true);
        }
    }
}
