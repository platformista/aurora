<?php

use App\Domain\RuleRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Called synchronously by NFW over the internal network (http://promotions.internal).
Route::post('/quote', function (Request $request, RuleRepository $rules) {
    $data = $request->validate([
        'lines' => ['required', 'array', 'min:1'],
        'lines.*.sku' => ['required', 'string'],
        'lines.*.category' => ['required', 'string'],
        'lines.*.qty' => ['required', 'integer', 'min:1'],
        'lines.*.unit_price_cents' => ['required', 'integer', 'min:0'],
    ]);

    return $rules->engine()->quote($data['lines']);
});
