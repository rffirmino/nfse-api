<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('establishment_external_id', 150)->unique();
            $table->string('provider', 30)->default('asaas');
            $table->text('access_token'); // sempre criptografado (cast 'encrypted')
            $table->string('base_url', 150)->nullable();
            $table->string('environment', 20)->default('sandbox');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_accounts');
    }
};
