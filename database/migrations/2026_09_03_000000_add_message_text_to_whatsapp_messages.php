<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table): void {
            $table->text('message_text')->nullable()->after('template_language');
            $table->string('template_name', 150)->nullable()->change();
            $table->string('template_language', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table): void {
            $table->dropColumn('message_text');
            $table->string('template_name', 150)->nullable(false)->default('')->change();
            $table->string('template_language', 20)->nullable(false)->default('')->change();
        });
    }
};
