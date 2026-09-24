<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('appointment_external_id', 150)->nullable();
            $table->string('customer_external_id', 150)->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('external_id', 150)->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('invoice_number', 50)->nullable();
            $table->string('series', 30)->nullable();
            $table->string('access_key', 150)->nullable();
            $table->string('protocol', 150)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->decimal('tax_amount', 15, 2)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->longText('xml')->nullable();
            $table->text('document_url')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
            $table->index('appointment_external_id');
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_invoices');
    }
};
