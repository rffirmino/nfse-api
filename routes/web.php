<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('landing');
});

Route::get('/up', fn () => response('OK', 200)->header('Content-Type', 'text/plain'));

// Health check para monitoração (público, sem dados sensíveis).
Route::get('/health', function () {
    $queuePending = null;
    try {
        $queuePending = \Illuminate\Support\Facades\DB::table('jobs')->count();
    } catch (\Throwable) {
        $queuePending = null;
    }

    return response()->json([
        'status' => 'ok',
        'queue_pending' => $queuePending,
        'time' => now()->toIso8601String(),
    ]);
});

// Compatibilidade: URL antiga da Swagger UI agora redireciona para /docs.
Route::redirect('/api/documentation', '/docs');

// Public pages required by Meta App Review. These pages do not expose API credentials.
Route::view('/politica-de-privacidade', 'privacy-policy')->name('privacy-policy');
Route::view('/privacy-policy', 'privacy-policy');
Route::view('/exclusao-de-dados', 'data-deletion')->name('data-deletion');
