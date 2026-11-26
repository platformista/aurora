<?php

namespace App\Domain;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Where invoice PDFs live.
 *
 * On an environment with Upsun object storage (the `object-storage`
 * relationship exists), PDFs go to the S3-compatible bucket. Elsewhere (local
 * development, preview environments without a bucket) they go into the
 * database, because web and worker containers on Upsun do not share mounts.
 */
class InvoiceStore
{
    public function mode(): string
    {
        return config('filesystems.invoices_disk') === 's3' ? 's3' : 'database';
    }

    public function put(string $orderUuid, string $number, string $pdf): void
    {
        $row = [
            'number' => $number,
            'storage' => $this->mode(),
            'size_bytes' => strlen($pdf),
            'updated_at' => now(),
        ];

        if ($this->mode() === 's3') {
            $path = "invoices/{$number}.pdf";
            Storage::disk('s3')->put($path, $pdf, ['ContentType' => 'application/pdf']);
            $row += ['path' => $path, 'pdf' => null];
        } else {
            $row += ['path' => null, 'pdf' => $pdf];
        }

        DB::table('invoices')->updateOrInsert(['order_uuid' => $orderUuid], $row + ['created_at' => now()]);
    }

    public function get(string $orderUuid): ?string
    {
        $invoice = DB::table('invoices')->where('order_uuid', $orderUuid)->first();
        if (! $invoice) {
            return null;
        }

        return $invoice->storage === 's3' ? Storage::disk('s3')->get($invoice->path) : $invoice->pdf;
    }
}
