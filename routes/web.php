<?php

use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\ContactApiController;
use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\EntityApiController;
use App\Http\Controllers\Api\InviteApiController;
use App\Http\Controllers\Api\InboxApiController;
use App\Http\Controllers\Api\TicketAttachmentApiController;
use App\Http\Controllers\Api\TicketApiController;
use App\Http\Controllers\Api\TicketLogApiController;
use App\Http\Controllers\Api\TicketMessageApiController;
use App\Http\Controllers\Api\UserManagementApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('app-api')->group(function (): void {
    Route::post('/login', [AuthApiController::class, 'login'])->middleware('guest');
    Route::get('/invites/{token}', [InviteApiController::class, 'show'])->middleware('guest');
    Route::post('/invites/accept', [InviteApiController::class, 'accept'])->middleware('guest');

    Route::middleware('auth')->group(function (): void {
        Route::get('/me', [AuthApiController::class, 'me']);
        Route::post('/logout', [AuthApiController::class, 'logout']);

        Route::get('/meta', [TicketApiController::class, 'meta']);
        Route::get('/dashboard/summary', [DashboardApiController::class, 'summary']);
        Route::get('/tickets', [TicketApiController::class, 'index']);
        Route::post('/tickets', [TicketApiController::class, 'store']);
        Route::get('/tickets/{ticket}', [TicketApiController::class, 'show']);
        Route::patch('/tickets/{ticket}', [TicketApiController::class, 'update'])->middleware('operator');
        Route::patch('/tickets/{ticket}/metadata', [TicketApiController::class, 'updateMetadata'])->middleware('operator');

        Route::patch('/tickets/{ticket}/status', [TicketApiController::class, 'updateStatus'])->middleware('operator');
        Route::patch('/tickets/{ticket}/assignment', [TicketApiController::class, 'updateAssignment'])->middleware('operator');
        Route::post('/tickets/{ticket}/messages', [TicketMessageApiController::class, 'store']);
        Route::get('/attachments/{attachment}/download', [TicketAttachmentApiController::class, 'download'])
            ->name('app-api.attachments.download');

        Route::middleware(['operator', 'manage-users'])->group(function (): void {
            Route::get('/inboxes', [InboxApiController::class, 'index']);
            Route::post('/inboxes', [InboxApiController::class, 'store']);
            Route::patch('/inboxes/{inbox}', [InboxApiController::class, 'update']);
            Route::delete('/inboxes/{inbox}', [InboxApiController::class, 'destroy']);

            Route::get('/entities', [EntityApiController::class, 'index']);
            Route::post('/entities', [EntityApiController::class, 'store']);
            Route::patch('/entities/{entity}', [EntityApiController::class, 'update']);
            Route::delete('/entities/{entity}', [EntityApiController::class, 'destroy']);

            Route::get('/contacts', [ContactApiController::class, 'index']);
            Route::post('/contacts', [ContactApiController::class, 'store']);
            Route::patch('/contacts/{contact}', [ContactApiController::class, 'update']);
            Route::delete('/contacts/{contact}', [ContactApiController::class, 'destroy']);

            Route::get('/ticket-logs', [TicketLogApiController::class, 'index']);
            Route::get('/ticket-logs/{ticketLog}', [TicketLogApiController::class, 'show']);

            Route::get('/users/meta', [UserManagementApiController::class, 'meta']);
            Route::get('/users', [UserManagementApiController::class, 'index']);
            Route::post('/users', [UserManagementApiController::class, 'store']);
            Route::patch('/users/{user}', [UserManagementApiController::class, 'update']);
            Route::delete('/users/{user}', [UserManagementApiController::class, 'destroy']);
            Route::patch('/users/{user}/inboxes', [UserManagementApiController::class, 'updateInboxes']);
            Route::post('/users/{user}/invite', [UserManagementApiController::class, 'invite']);
        });
    });
});

Route::view('/{any?}', 'spa')
    ->where('any', '^(?!app-api|up).*$');


