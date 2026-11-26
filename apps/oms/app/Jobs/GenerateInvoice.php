<?php

namespace App\Jobs;

use App\Domain\InvoiceStore;
use App\Models\Shipment;
use Dompdf\Dompdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateInvoice implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [5, 15, 60];

    public function __construct(public int $shipmentId) {}

    public function handle(InvoiceStore $store): void
    {
        $shipment = Shipment::findOrFail($this->shipmentId);
        $number = sprintf('FT-%s-%06d', $shipment->shipped_at->format('Y'), $shipment->id);

        $dompdf = new Dompdf;
        $dompdf->loadHtml(view('invoice', ['shipment' => $shipment, 'number' => $number])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        $store->put($shipment->order_uuid, $number, $dompdf->output());
    }
}
