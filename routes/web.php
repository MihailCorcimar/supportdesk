<?php

use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\TicketAttachmentApiController;
use App\Http\Controllers\Api\TicketApiController;
use App\Http\Controllers\Api\TicketMessageApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('app-api')->group(function (): void {
    Route::post('/login', [AuthApiController::class, 'login'])->middleware('guest');

    Route::middleware('auth')->group(function (): void {
        Route::get('/me', [AuthApiController::class, 'me']);
        Route::post('/logout', [AuthApiController::class, 'logout']);

        Route::get('/meta', [TicketApiController::class, 'meta']);
        Route::get('/dashboard/summary', [DashboardApiController::class, 'summary']);
        Route::get('/tickets', [TicketApiController::class, 'index']);
        Route::post('/tickets', [TicketApiController::class, 'store']);
        Route::get('/tickets/{ticket}', [TicketApiController::class, 'show']);
        Route::patch('/tickets/{ticket}/metadata', [TicketApiController::class, 'updateMetadata'])->middleware('operator');

        Route::patch('/tickets/{ticket}/status', [TicketApiController::class, 'updateStatus'])->middleware('operator');
        Route::patch('/tickets/{ticket}/assignment', [TicketApiController::class, 'updateAssignment'])->middleware('operator');
        Route::post('/tickets/{ticket}/messages', [TicketMessageApiController::class, 'store']);
        Route::get('/attachments/{attachment}/download', [TicketAttachmentApiController::class, 'download'])
            ->name('app-api.attachments.download');
    });
});

Route::view('/{any?}', 'spa')
    ->where('any', '^(?!app-api|up).*$');
