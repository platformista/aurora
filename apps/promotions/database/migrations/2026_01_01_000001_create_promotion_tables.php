<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('label');
            $table->string('type', 32);      // percent_over_threshold, gift_with_product, category_quantity
            $table->json('params');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Redemption ledger: which promotion was used on which order.
        Schema::create('redemptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('order_uuid');
            $table->string('rule_code', 32);
            $table->unsignedInteger('discount_cents');
            $table->string('seller_code', 16);
            $table->timestamp('redeemed_at');
            $table->unique(['order_uuid', 'rule_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redemptions');
        Schema::dropIfExists('promotion_rules');
    }
};
