<?php

namespace App\Console\Commands;

use App\Domain\DemoData;
use Illuminate\Console\Command;

class SeedVolume extends Command
{
    protected $signature = 'aurora:seed-volume {--sellers=50000} {--orders=500000} {--force : Do not ask for confirmation}';

    protected $description = 'Replace sellers and orders with the demo volume (fictitious data)';

    public function handle(DemoData $data): int
    {
        $sellers = (int) $this->option('sellers');
        $orders = (int) $this->option('orders');

        if (! $this->option('force') && ! $this->confirm("Replace all sellers, orders and commissions with {$sellers} sellers and {$orders} orders?")) {
            return self::FAILURE;
        }

        $started = microtime(true);
        $data->wipe();
        $data->products();

        $bar = $this->output->createProgressBar($sellers + $orders);
        $data->sellers($sellers, fn ($i) => $bar->setProgress($i));
        $data->orders($orders, $sellers, fn ($i) => $bar->setProgress($sellers + $i));
        $bar->finish();

        $this->newLine();
        $this->components->info(sprintf('Done in %.0f s.', microtime(true) - $started));

        return self::SUCCESS;
    }
}
