<?php

use App\Http\Controllers\Api\AuditLogsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\DamagesController;
use App\Http\Controllers\Api\EventsController;
use App\Http\Controllers\Api\ReservationRulesController;
use App\Http\Controllers\Api\ReservationsController;
use App\Http\Controllers\Api\ResourcesController;
use App\Http\Controllers\Api\UsageStatsController;
use App\Http\Controllers\Api\UsersController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes (no auth required)
|--------------------------------------------------------------------------
| Anonymous visitors can browse the inventory, create reservations, report
| damages and run events. The audit log, resource inventory edits and user
| management are the three things gated behind login (see groups below).
*/

Route::post('auth/login', [AuthController::class, 'login']);

Route::get('availability/dashboard', [AvailabilityController::class, 'dashboard']);

// Read-only resource browsing is public; writes are admin-only (see group below).
Route::get('resources', [ResourcesController::class, 'index']);
Route::get('resources/{id}', [ResourcesController::class, 'show']);

Route::apiResource('reservations', ReservationsController::class)
    ->parameters(['reservations' => 'id']);
Route::patch('reservations/{id}/cancel', [ReservationsController::class, 'cancel']);
Route::get('reservations/{id}/ics', [ReservationsController::class, 'ics']);

Route::apiResource('events', EventsController::class)
    ->parameters(['events' => 'id']);
Route::get('events/{id}/participants', [EventsController::class, 'listParticipants']);
Route::post('events/{id}/participants', [EventsController::class, 'addParticipant']);
Route::delete('events/{id}/participants/{participantId}', [EventsController::class, 'removeParticipant']);
Route::post('events/{id}/reservations', [EventsController::class, 'attachResources']);

Route::apiResource('damages', DamagesController::class)
    ->parameters(['damages' => 'id']);
Route::get('damages/{id}/photo', [DamagesController::class, 'showPhoto']);
Route::post('damages/{id}/photo', [DamagesController::class, 'addPhoto']);
Route::delete('damages/{id}/photo', [DamagesController::class, 'removePhoto']);

// Reservation rules — public read so anonymous bookers can see them;
// PATCH is in the admin group below.
Route::get('reservation-rules', [ReservationRulesController::class, 'show']);

/*
|--------------------------------------------------------------------------
| Auth required (MEMBER or ADMIN)
|--------------------------------------------------------------------------
| Token issued by /auth/login as `Authorization: Bearer <token>`.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::get('audit-logs', [AuditLogsController::class, 'index']);
});

/*
|--------------------------------------------------------------------------
| Admin only
|--------------------------------------------------------------------------
| Resource inventory edits + user management.
*/

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('resources', [ResourcesController::class, 'store']);
    Route::patch('resources/{id}', [ResourcesController::class, 'update']);
    Route::delete('resources/{id}', [ResourcesController::class, 'destroy']);
    Route::patch('resources/{id}/deactivate', [ResourcesController::class, 'deactivate']);
    Route::patch('resources/{id}/activate', [ResourcesController::class, 'activate']);

    Route::apiResource('users', UsersController::class)
        ->parameters(['users' => 'id']);

    Route::patch('reservation-rules', [ReservationRulesController::class, 'update']);

    Route::get('admin/usage-stats', [UsageStatsController::class, 'show']);
});
