<?php

use App\Http\Controllers\Api\AuditLogsController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\DamagesController;
use App\Http\Controllers\Api\EventsController;
use App\Http\Controllers\Api\ReservationsController;
use App\Http\Controllers\Api\ResourcesController;
use Illuminate\Support\Facades\Route;

Route::apiResource('resources', ResourcesController::class)
    ->parameters(['resources' => 'id']);
Route::patch('resources/{id}/deactivate', [ResourcesController::class, 'deactivate']);
Route::patch('resources/{id}/activate', [ResourcesController::class, 'activate']);

Route::apiResource('reservations', ReservationsController::class)
    ->parameters(['reservations' => 'id']);
Route::patch('reservations/{id}/cancel', [ReservationsController::class, 'cancel']);

Route::apiResource('events', EventsController::class)
    ->parameters(['events' => 'id']);
Route::get('events/{id}/participants', [EventsController::class, 'listParticipants']);
Route::post('events/{id}/participants', [EventsController::class, 'addParticipant']);
Route::delete('events/{id}/participants/{participantId}', [EventsController::class, 'removeParticipant']);
Route::post('events/{id}/reservations', [EventsController::class, 'attachResources']);

Route::apiResource('damages', DamagesController::class)
    ->parameters(['damages' => 'id']);

Route::get('availability/dashboard', [AvailabilityController::class, 'dashboard']);

Route::get('audit-logs', [AuditLogsController::class, 'index']);
