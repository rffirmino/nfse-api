<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Entrega da NFS-e ao cliente final (WhatsApp, e-mail e download no app
        // do sistema do cliente). O establishment habilita cada canal; aqui
        // registramos o que foi pedido, o resultado e o rastreio.
        Schema::table('nfse_invoices', function (Blueprint $table): void {
            $table->string('customer_name', 150)->nullable()->after('customer_external_id');
            $table->string('customer_phone', 20)->nullable()->after('customer_name');
            $table->string('customer_email', 150)->nullable()->after('customer_phone');
            $table->json('delivery_channels')->nullable()->after('customer_email');
            $table->string('delivery_status', 30)->default('not_requested')->after('delivery_channels');
            $table->unsignedTinyInteger('delivery_attempts')->default(0)->after('delivery_status');
            $table->timestamp('delivered_at')->nullable()->after('delivery_attempts');
            $table->json('delivery_errors')->nullable()->after('delivered_at');

            $table->index(['delivery_status', 'status'], 'nfse_invoices_delivery_status_index');
        });

        // Default de entrega por estabelecimento/município: usado quando o
        // sistema do cliente não enviar os canais explicitamente no POST.
        Schema::table('fiscal_configurations', function (Blueprint $table): void {
            $table->json('default_delivery_channels')->nullable()->after('issue_on_payment');
        });
    }

    public function down(): void
    {
        Schema::table('nfse_invoices', function (Blueprint $table): void {
            $table->dropIndex('nfse_invoices_delivery_status_index');
            $table->dropColumn([
                'customer_name', 'customer_phone', 'customer_email', 'delivery_channels',
                'delivery_status', 'delivery_attempts', 'delivered_at', 'delivery_errors',
            ]);
        });

        Schema::table('fiscal_configurations', function (Blueprint $table): void {
            $table->dropColumn('default_delivery_channels');
        });
    }
};