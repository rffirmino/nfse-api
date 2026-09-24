<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table): void {
            // Identifica o estabelecimento/tenant de origem (fase 2: número próprio por estabelecimento).
            $table->string('establishment_external_id', 150)->nullable()->after('event');
            $table->index('establishment_external_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table): void {
            $table->dropIndex(['establishment_external_id']);
            $table->dropColumn('establishment_external_id');
        });
    }
};
