<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();          // also the event ID consumers deduplicate on
            $table->string('topic');
            $table->string('type');
            $table->string('message_key');          // Kafka key: keeps one order's events in order
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempt_at', 6)->nullable();
            $table->timestamp('published_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['published_at', 'created_at']);
        });

        Schema::create('processed_events', function (Blueprint $table) {
            $table->uuid('event_id');
            $table->string('consumer', 64);
            $table->string('event_type');
            $table->timestamp('processed_at', 6);
            $table->primary(['event_id', 'consumer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_events');
        Schema::dropIfExists('outbox_events');
    }
};
