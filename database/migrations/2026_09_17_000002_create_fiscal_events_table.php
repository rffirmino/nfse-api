<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 30)->default('asaas');
            $table->string('external_event_id', 191)->unique(); // idempotência
            $table->string('event_type', 60)->nullable();
            $table->string('invoice_external_id', 150)->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_events');
    }
};
