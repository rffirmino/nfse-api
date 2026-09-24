<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfse_invoices', function (Blueprint $table): void {
            $table->string('idempotency_key', 255)->nullable()->after('emission_requested');
            $table->unique('idempotency_key', 'nfse_invoice_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('nfse_invoices', function (Blueprint $table): void {
            $table->dropUnique('nfse_invoice_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
