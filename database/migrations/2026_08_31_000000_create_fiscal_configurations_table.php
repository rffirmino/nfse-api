<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_configurations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('establishment_external_id', 150);
            $table->string('municipality_code', 20);
            $table->string('municipality_name', 150);
            $table->string('provider', 80)->default('nfse_nacional');
            $table->string('environment', 30)->default('restricted');
            $table->string('provider_registration', 80)->nullable();
            $table->string('tax_regime', 40);
            $table->string('service_code', 40);
            $table->string('cnae', 20)->nullable();
            $table->string('nbs', 30)->nullable();
            $table->decimal('iss_rate', 7, 4)->nullable();
            $table->json('retention_rules')->nullable();
            $table->string('operation_nature', 120)->nullable();
            $table->string('incidence_municipality_code', 20)->nullable();
            $table->boolean('issue_on_payment')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['establishment_external_id', 'municipality_code', 'service_code'], 'fiscal_config_scope_unique');
            $table->index(['establishment_external_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_configurations');
    }
};
