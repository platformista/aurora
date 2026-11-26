<?php

use App\Domain\InvoiceStore;
use App\Messaging\Stats;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// The OMS has no public route on Upsun: these endpoints are reached by NFW
// over the internal network (http://oms.internal).

Route::get('/', fn () => ['app' => 'oms', 'status' => 'ok']);

Route::get('/internal/stats', fn (Stats $stats, InvoiceStore $store) => $stats->snapshot() + [
    'shipments' => Shipment::count(),
    'invoices' => DB::table('invoices')->count(),
    'invoice_storage' => $store->mode() === 's3' ? 'object storage' : 'database',
]);

Route::get('/internal/invoices/{uuid}', function (string $uuid, InvoiceStore $store) {
    $pdf = $store->get($uuid);
    abort_if($pdf === null, 404);

    return response($pdf, 200, ['Content-Type' => 'application/pdf']);
});
