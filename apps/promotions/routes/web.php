<?php

use App\Messaging\Stats;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => ['app' => 'promotions', 'status' => 'ok']);

Route::get('/internal/stats', fn (Stats $stats) => $stats->snapshot() + [
    'redemptions' => DB::table('redemptions')->count(),
]);
