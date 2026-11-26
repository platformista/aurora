<?php

namespace Database\Seeders;

use App\Domain\DemoData;
use Illuminate\Database\Seeder;

/** Small data set for local development and tests. For the demo volume use `php artisan aurora:seed-volume`. */
class DatabaseSeeder extends Seeder
{
    public function run(DemoData $data): void
    {
        $data->wipe();
        $data->products();
        $data->sellers(300);
        $data->orders(4000, 300);
    }
}
