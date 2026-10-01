<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Inscrição municipal do prestador: exigida por alguns provedores e útil
        // para conferência do cadastro fiscal do estabelecimento.
        Schema::table('fiscal_configurations', function (Blueprint $table): void {
            $table->string('municipal_inscription', 40)->nullable()->after('provider_registration');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_configurations', function (Blueprint $table): void {
            $table->dropColumn('municipal_inscription');
        });
    }
};