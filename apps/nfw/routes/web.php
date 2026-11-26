<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\NetworkController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\SessionController;
use App\Http\Middleware\RequireSeller;
use Illuminate\Support\Facades\Route;

Route::get('/accedi', [SessionController::class, 'show'])->name('login');
Route::post('/accedi', [SessionController::class, 'store']);
Route::post('/esci', [SessionController::class, 'destroy'])->name('logout');

Route::middleware(RequireSeller::class)->group(function () {
    Route::redirect('/', '/ordini/nuovo');
    Route::get('/ordini/nuovo', [OrderController::class, 'create'])->name('orders.create');
    Route::post('/ordini/preventivo', [OrderController::class, 'quote'])->name('orders.quote');
    Route::post('/ordini', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/ordini', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/ordini/{uuid}/fattura', [OrderController::class, 'invoice'])->name('orders.invoice');
    Route::get('/rete', [NetworkController::class, 'show'])->name('network');
    Route::get('/provvigioni', [CommissionController::class, 'show'])->name('commissions');
});

// Demo control panel. No authentication: protect the environment with Upsun HTTP access control.
Route::get('/admin', [AdminController::class, 'show'])->name('admin');
Route::get('/admin/stats', [AdminController::class, 'stats'])->name('admin.stats');
Route::post('/admin/broker', [AdminController::class, 'broker'])->name('admin.broker');
Route::post('/admin/provvigioni', [AdminController::class, 'commissions'])->name('admin.commissions');
