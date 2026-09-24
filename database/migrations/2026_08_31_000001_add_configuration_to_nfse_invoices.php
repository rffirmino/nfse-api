<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfse_invoices', function (Blueprint $table): void {
            $table->uuid('fiscal_configuration_id')->nullable()->after('id');
            $table->string('establishment_external_id', 150)->nullable()->after('fiscal_configuration_id');
            $table->boolean('emission_requested')->default(true)->after('status');
            $table->index(['establishment_external_id', 'status']);
            $table->index('fiscal_configuration_id');
        });
    }

    public function down(): void
    {
        Schema::table('nfse_invoices', function (Blueprint $table): void {
            $table->dropIndex(['establishment_external_id', 'status']);
            $table->dropIndex(['fiscal_configuration_id']);
            $table->dropColumn(['fiscal_configuration_id', 'establishment_external_id', 'emission_requested']);
        });
    }
};
