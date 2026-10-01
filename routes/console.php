<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reconciliação fiscal (rede de segurança do webhook do Asaas).
Schedule::command('fiscal:reconcile')->everyFiveMinutes()->withoutOverlapping();

// Entrega da NFS-e ao cliente final (retry de WhatsApp/e-mail).
Schedule::command('nfse:deliver --retry-failed')->everyTenMinutes()->withoutOverlapping();
