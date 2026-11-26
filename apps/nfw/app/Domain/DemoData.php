<?php

namespace App\Domain;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fictitious data: invented names, products and cities. No real brand, person or customer.
 */
class DemoData
{
    public const PRODUCTS = [
        ['VIS-001', 'Detergente micellare alla camomilla', 'Viso', 990],
        ['VIS-002', 'Siero idratante all\'acido ialuronico', 'Viso', 2490],
        ['VIS-003', 'Crema giorno protettiva SPF 20', 'Viso', 1990],
        ['VIS-004', 'Crema notte rigenerante', 'Viso', 2290],
        ['VIS-005', 'Contorno occhi al fiordaliso', 'Viso', 1650],
        ['COR-001', 'Latte corpo alla mandorla', 'Corpo', 1290],
        ['COR-002', 'Scrub zucchero e limone', 'Corpo', 1450],
        ['COR-003', 'Burro corpo al karité', 'Corpo', 1790],
        ['COR-004', 'Olio secco nutriente', 'Corpo', 1950],
        ['COR-005', 'Gel doccia agli agrumi', 'Corpo', 590],
        ['CAP-001', 'Shampoo delicato all\'avena', 'Capelli', 790],
        ['CAP-002', 'Balsamo districante', 'Capelli', 850],
        ['CAP-003', 'Maschera ristrutturante', 'Capelli', 1390],
        ['PRO-001', 'Acqua profumata Fico d\'India', 'Profumi', 2990],
        ['PRO-002', 'Eau de parfum Gelsomino notturno', 'Profumi', 3990],
        ['PRO-003', 'Brume Lavanda di collina', 'Profumi', 1890],
        ['TRU-001', 'Mascara volume intenso', 'Trucco', 1290],
        ['TRU-002', 'Rossetto satinato Corallo', 'Trucco', 1150],
        ['TRU-003', 'Fondotinta fluido naturale', 'Trucco', 1850],
        ['TRU-004', 'Matita occhi Bosco', 'Trucco', 750],
    ];

    private const FIRST = ['Giulia', 'Francesca', 'Chiara', 'Sara', 'Martina', 'Elena', 'Valentina', 'Alessia', 'Silvia', 'Federica',
        'Laura', 'Paola', 'Simona', 'Roberta', 'Anna', 'Marta', 'Elisa', 'Ilaria', 'Beatrice', 'Camilla', 'Serena', 'Monica',
        'Daniela', 'Cristina', 'Barbara', 'Lucia', 'Arianna', 'Noemi', 'Irene', 'Veronica', 'Marco', 'Luca', 'Andrea', 'Matteo'];

    private const LAST = ['Rossi', 'Bianchi', 'Ferrari', 'Esposito', 'Romano', 'Colombo', 'Ricci', 'Marino', 'Greco', 'Bruno',
        'Gallo', 'Conti', 'De Luca', 'Mancini', 'Costa', 'Giordano', 'Rizzo', 'Lombardi', 'Moretti', 'Barbieri', 'Fontana',
        'Santoro', 'Mariani', 'Rinaldi', 'Caruso', 'Ferri', 'Galli', 'Martini', 'Leone', 'Longo', 'Gentile', 'Martinelli',
        'Vitale', 'Lombardo', 'Serra', 'Coppola', 'De Santis', 'Marchetti', 'Parisi', 'Villa', 'Conte', 'Fabbri', 'Bellini'];

    private const CITIES = [['Pontedera', 'PI'], ['Pisa', 'PI'], ['Livorno', 'LI'], ['Lucca', 'LU'], ['Firenze', 'FI'], ['Siena', 'SI'],
        ['Arezzo', 'AR'], ['Prato', 'PO'], ['Pistoia', 'PT'], ['Grosseto', 'GR'], ['Bologna', 'BO'], ['Milano', 'MI'],
        ['Torino', 'TO'], ['Genova', 'GE'], ['Roma', 'RM'], ['Napoli', 'NA'], ['Bari', 'BA'], ['Palermo', 'PA'],
        ['Verona', 'VR'], ['Padova', 'PD'], ['Perugia', 'PG'], ['Ancona', 'AN'], ['Cagliari', 'CA'], ['Trento', 'TN']];

    public function products(): void
    {
        $now = now();
        DB::table('products')->upsert(array_map(fn ($p) => [
            'sku' => $p[0], 'name' => $p[1], 'category' => $p[2], 'price_cents' => $p[3], 'created_at' => $now, 'updated_at' => $now,
        ], self::PRODUCTS), ['sku'], ['name', 'category', 'price_cents', 'updated_at']);
    }

    /**
     * Sellers in a tree about 8-10 levels deep: each new seller is sponsored by
     * someone who joined earlier.
     */
    public function sellers(int $count, ?callable $progress = null): void
    {
        mt_srand(2026);
        $depth = [];
        $rows = [];
        $now = now();

        for ($i = 1; $i <= $count; $i++) {
            $upline = $i === 1 ? null : mt_rand(max(1, intdiv($i, 8)), max(1, intdiv($i, 3)));
            $depth[$i] = $upline ? $depth[$upline] + 1 : 0;
            $first = self::FIRST[mt_rand(0, count(self::FIRST) - 1)];
            $last = self::LAST[mt_rand(0, count(self::LAST) - 1)];
            [$city, $prov] = self::CITIES[mt_rand(0, count(self::CITIES) - 1)];

            $rows[] = [
                'id' => $i,
                'code' => sprintf('INC-%06d', $i),
                'first_name' => $first,
                'last_name' => $last,
                'email' => Str::lower(Str::ascii("{$first}.{$last}.{$i}@aurora-demo.example")),
                'city' => $city,
                'province' => $prov,
                'upline_id' => $upline,
                'depth' => $depth[$i],
                'joined_at' => $now->copy()->subDays(mt_rand(30, 3000))->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === 2000) {
                DB::table('incaricate')->insert($rows);
                $rows = [];
                $progress && $progress($i);
            }
        }
        if ($rows) {
            DB::table('incaricate')->insert($rows);
        }
    }

    /**
     * Historical orders, already shipped, spread over the current and previous
     * sales cycle. They carry no outbox events: they predate the demo.
     */
    public function orders(int $count, int $sellers, ?callable $progress = null): void
    {
        mt_srand(4242);
        $current = SalesCycle::current();
        $previous = $current->previous();
        $now = now();
        $currentSpan = max(1, $current->start()->diffInSeconds($now, true));
        $previousSpan = SalesCycle::DAYS * 86400;
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            // Uneven activity: a minority of sellers places most orders, many stay below the threshold.
            $seller = 1 + (int) floor(($sellers - 1) * (mt_rand() / mt_getrandmax()) ** 1.7);
            $total = 1500 + (int) floor(23500 * (mt_rand() / mt_getrandmax()) ** 2);
            $placed = mt_rand(1, 100) <= 80
                ? $current->start()->addSeconds(mt_rand(0, (int) $currentSpan))
                : $previous->start()->addSeconds(mt_rand(0, $previousSpan - 1));

            $rows[] = [
                'uuid' => (string) Str::uuid7($placed),
                'seller_id' => $seller,
                'status' => 'shipped',
                'subtotal_cents' => $total,
                'discount_cents' => 0,
                'total_cents' => $total,
                'placed_at' => $placed,
                'shipped_at' => $placed->addHours(20),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === 2000) {
                DB::table('orders')->insert($rows);
                $rows = [];
                $progress && $progress($i);
            }
        }
        if ($rows) {
            DB::table('orders')->insert($rows);
        }
    }

    public function wipe(): void
    {
        foreach (['commissions', 'commission_chunks', 'commission_runs', 'order_lines', 'orders', 'incaricate'] as $table) {
            DB::table($table)->delete();
        }
    }
}
