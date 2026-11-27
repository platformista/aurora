<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('promotion_rules')->insert([
            'code' => 'COFANETTO-VISO', 'label' => '20% su 3 o più prodotti Viso', 'type' => 'category_quantity',
            'params' => json_encode(['category' => 'Viso', 'min_qty' => 3, 'percent' => 20]), 'active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('promotion_rules')->where('code', 'COFANETTO-VISO')->delete();
    }
};
