<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sellers ("incaricate") in a hierarchy: each has at most one upline.
        Schema::create('incaricate', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('city');
            $table->string('province', 2);
            $table->unsignedBigInteger('upline_id')->nullable()->index();
            $table->unsignedSmallInteger('depth')->default(0);
            $table->date('joined_at');
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 16)->unique();
            $table->string('name');
            $table->string('category');
            $table->unsignedInteger('price_cents');
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('seller_id')->index();
            $table->string('status', 16)->default('placed');   // placed -> shipped
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('total_cents');
            $table->json('promotions')->nullable();
            $table->json('gifts')->nullable();
            $table->string('carrier')->nullable();
            $table->string('tracking_code')->nullable();
            $table->timestamp('placed_at')->index();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('sku', 16);
            $table->string('name');
            $table->unsignedSmallInteger('qty');
            $table->unsignedInteger('unit_price_cents');
        });

        Schema::create('commission_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('cycle');
            $table->string('status', 16);                      // queued, running, completed, failed
            $table->string('triggered_by', 16);                // cron, ui, cli
            $table->unsignedInteger('chunks')->default(0);
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('beneficiaries_count')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->timestamp('queued_at', 3);
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('finished_at', 3)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        // Partial results of each parallel chunk, merged by the final job.
        Schema::create('commission_chunks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id');
            $table->unsignedInteger('chunk_no');
            $table->longText('payload');
            $table->unique(['run_id', 'chunk_no']);
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('seller_id');
            $table->unsignedSmallInteger('cycle');
            $table->unsignedBigInteger('personal_volume_cents');
            $table->unsignedBigInteger('personal_cents');
            $table->unsignedBigInteger('network_cents');
            $table->primary(['run_id', 'seller_id']);
            $table->index(['seller_id', 'cycle']);
        });
    }

    public function down(): void
    {
        foreach (['commissions', 'commission_chunks', 'commission_runs', 'order_lines', 'orders', 'products', 'incaricate'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
