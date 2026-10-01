<?php

use App\Http\Controllers\MessageController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\FiscalAccountController;
use App\Http\Controllers\FiscalExportController;
use App\Http\Controllers\AsaasFiscalWebhookController;
use App\Http\Controllers\InboundMessageController;
use App\Http\Controllers\WhatsAppSenderController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Middleware\VerifyInternalHmac;
use Illuminate\Support\Facades\Route;

Route::get('/v1/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
Route::post('/v1/whatsapp/webhook', [WhatsAppWebhookController::class, 'receive']);

// Webhook público do Asaas para NFS-e (validado pelo token do webhook).
Route::post('/v1/fiscal/webhook/asaas', [AsaasFiscalWebhookController::class, 'receive']);

Route::middleware(VerifyInternalHmac::class)->post('/v1/messages', [MessageController::class, 'store']);
Route::middleware(VerifyInternalHmac::class)->get('/v1/messages/{message}', [MessageController::class, 'show']);
Route::middleware(VerifyInternalHmac::class)->post('/v1/invoices', [InvoiceController::class, 'store']);
Route::middleware(VerifyInternalHmac::class)->get('/v1/invoices', [InvoiceController::class, 'index']);
Route::middleware(VerifyInternalHmac::class)->get('/v1/invoices/{invoice}', [InvoiceController::class, 'show']);
Route::middleware(VerifyInternalHmac::class)->get('/v1/invoices/{invoice}/document', [InvoiceController::class, 'document']);
Route::middleware(VerifyInternalHmac::class)->post('/v1/invoices/{invoice}/manual', [InvoiceController::class, 'manual']);
Route::middleware(VerifyInternalHmac::class)->post('/v1/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);
Route::middleware(VerifyInternalHmac::class)->get('/v1/fiscal/coverage', [InvoiceController::class, 'coverage']);
Route::middleware(VerifyInternalHmac::class)->get('/v1/fiscal/accounts', [FiscalAccountController::class, 'index']);
Route::middleware(VerifyInternalHmac::class)->post('/v1/fiscal/accounts', [FiscalAccountController::class, 'upsert']);
Route::middleware(VerifyInternalHmac::class)->get('/v1/fiscal/export', [FiscalExportController::class, 'export']);

Route::middleware(VerifyInternalHmac::class)->get('/v1/inbound-messages', [InboundMessageController::class, 'index']);
Route::middleware(VerifyInternalHmac::class)->get('/v1/inbound-messages/{inbound}', [InboundMessageController::class, 'show']);

Route::middleware(VerifyInternalHmac::class)->get('/v1/whatsapp/senders', [WhatsAppSenderController::class, 'index']);
Route::middleware(VerifyInternalHmac::class)->post('/v1/whatsapp/senders', [WhatsAppSenderController::class, 'upsert']);
