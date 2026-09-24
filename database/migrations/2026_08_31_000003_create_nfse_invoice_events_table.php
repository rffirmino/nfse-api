<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse_invoice_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('invoice_id');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('event', 60);
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['invoice_id', 'created_at']);
            $table->index('to_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_invoice_events');
    }
};
