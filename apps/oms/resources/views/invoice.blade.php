<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #333; }
    h1 { font-size: 18px; color: #1f5f4a; margin: 0 0 4px; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { padding: 6px 4px; border-bottom: 1px solid #ddd; text-align: left; }
    td.r, th.r { text-align: right; }
    .muted { color: #888; }
    .demo { margin-top: 24px; padding: 8px; border: 1px dashed #c00; color: #c00; }
</style>
</head>
<body>
    <h1>Fattura {{ $number }}</h1>
    <div class="muted">Aurora Demo S.r.l. · società inventata · P.IVA 00000000000</div>
    <p>
        Destinataria: <strong>{{ $shipment->seller_name }}</strong> ({{ $shipment->seller_code }}), {{ $shipment->city }}<br>
        Ordine: {{ $shipment->order_uuid }}<br>
        Spedizione: {{ $shipment->carrier }}, tracking {{ $shipment->tracking_code }}, {{ $shipment->shipped_at->format('d/m/Y H:i') }}
    </p>
    <table>
        <tr><th>Codice</th><th>Articolo</th><th class="r">Q.tà</th><th class="r">Prezzo</th><th class="r">Importo</th></tr>
        @foreach ($shipment->lines as $line)
            <tr>
                <td>{{ $line['sku'] }}</td>
                <td>{{ $line['name'] }}</td>
                <td class="r">{{ $line['qty'] }}</td>
                <td class="r">€ {{ number_format($line['unit_price_cents'] / 100, 2, ',', '.') }}</td>
                <td class="r">€ {{ number_format($line['qty'] * $line['unit_price_cents'] / 100, 2, ',', '.') }}</td>
            </tr>
        @endforeach
        @foreach ($shipment->gifts ?? [] as $gift)
            <tr><td>{{ $gift['sku'] }}</td><td>{{ $gift['name'] }} (omaggio)</td><td class="r">1</td><td class="r">€ 0,00</td><td class="r">€ 0,00</td></tr>
        @endforeach
        <tr><th colspan="4" class="r">Totale (sconti inclusi)</th><th class="r">€ {{ number_format($shipment->total_cents / 100, 2, ',', '.') }}</th></tr>
    </table>
    <div class="demo">Documento dimostrativo, privo di valore fiscale. Dati inventati.</div>
</body>
</html>
