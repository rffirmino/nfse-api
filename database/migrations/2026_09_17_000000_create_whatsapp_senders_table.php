<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_senders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('establishment_external_id', 150)->unique();
            $table->string('phone_number_id', 60);
            $table->string('waba_id', 60)->nullable();
            $table->string('display_name', 150)->nullable();
            $table->text('access_token'); // sempre criptografado (cast 'encrypted')
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_senders');
    }
};
