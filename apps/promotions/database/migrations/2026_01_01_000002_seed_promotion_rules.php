<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Reference data, so every environment starts with the same three rules.
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('promotion_rules')->insert([
            ['code' => 'SOGLIA-80', 'label' => '10% di sconto sopra 80 €', 'type' => 'percent_over_threshold',
                'params' => json_encode(['threshold_cents' => 8000, 'percent' => 10]), 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'OMAGGIO-NOTTE', 'label' => 'Balsamo labbra mini in omaggio con la Crema notte', 'type' => 'gift_with_product',
                'params' => json_encode(['trigger_sku' => 'VIS-004', 'gift_sku' => 'OMG-001', 'gift_name' => 'Balsamo labbra mini']), 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'TRIO-CORPO', 'label' => '15% su 3 o più prodotti Corpo', 'type' => 'category_quantity',
                'params' => json_encode(['category' => 'Corpo', 'min_qty' => 3, 'percent' => 15]), 'active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        DB::table('promotion_rules')->whereIn('code', ['SOGLIA-80', 'OMAGGIO-NOTTE', 'TRIO-CORPO'])->delete();
    }
};
