<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('idempotency_key', 255);
            $table->string('event', 100);
            $table->string('recipient_role', 30);
            $table->string('recipient_phone', 20);
            $table->string('template_name', 150);
            $table->string('template_language', 20);
            $table->json('template_parameters')->nullable();
            $table->string('appointment_external_id', 150)->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('provider', 80)->nullable();
            $table->string('external_message_id', 150)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'whatsapp_message_idempotency_unique');
            $table->index(['status', 'created_at']);
            $table->index('appointment_external_id');
            $table->index('external_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
