<?php

use App\Http\Controllers\Api\OrderApiController;
use Illuminate\Support\Facades\Route;

Route::post('/ordini', [OrderApiController::class, 'store']);
