<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_inbound_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('external_message_id', 150)->unique(); // wamid
            $table->string('wa_id', 30)->nullable()->index();      // remetente (wa_id)
            $table->string('contact_name', 150)->nullable();
            $table->string('message_type', 30)->nullable();        // text, image, audio...
            $table->text('text_body')->nullable();
            $table->string('media_id', 150)->nullable();
            $table->bigInteger('meta_timestamp')->nullable();      // unix (Meta)
            $table->json('raw_payload')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_inbound_messages');
    }
};
