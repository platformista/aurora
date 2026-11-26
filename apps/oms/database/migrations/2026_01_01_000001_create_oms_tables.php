<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->uuid('order_uuid')->unique();     // second line of defence against duplicates
            $table->string('seller_code', 16);
            $table->string('seller_name');
            $table->string('city');
            $table->json('lines');
            $table->json('gifts')->nullable();
            $table->unsignedInteger('total_cents');
            $table->string('carrier');
            $table->string('tracking_code')->unique();
            $table->string('status', 16);
            $table->timestamp('shipped_at');
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('order_uuid')->unique();
            $table->string('number')->unique();
            $table->string('storage', 16);            // "s3" (Upsun object storage) or "database"
            $table->string('path')->nullable();
            $table->binary('pdf')->nullable();        // only when storage = database
            $table->unsignedInteger('size_bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('shipments');
    }
};
