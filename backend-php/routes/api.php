<?php

use App\Http\Controllers\Api\AdminDataController;
use App\Http\Controllers\Api\AuditLogsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\DamagesController;
use App\Http\Controllers\Api\EventsController;
use App\Http\Controllers\Api\OAuthController;
use App\Http\Controllers\Api\PrivacyPolicyController;
use App\Http\Controllers\Api\ProfileController;
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
Route::post('auth/register', [AuthController::class, 'register']);
Route::get('auth/captcha', [AuthController::class, 'captcha']);
Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);
// Which social logins are live (empty while OAuth is dormant).
Route::get('auth/providers', [AuthController::class, 'providers']);
// OAuth redirect/callback are full-page navigations (not XHR). 404 while
// the provider is unconfigured. See docs/AUTH-AND-PERMISSIONS.md.
Route::get('auth/oauth/{provider}/redirect', [OAuthController::class, 'redirect']);
Route::get('auth/oauth/{provider}/callback', [OAuthController::class, 'callback']);

Route::get('availability/dashboard', [AvailabilityController::class, 'dashboard']);

// Read-only resource browsing is public; writes are admin-only (see group below).
Route::get('resources', [ResourcesController::class, 'index']);
Route::get('resources/{id}', [ResourcesController::class, 'show']);

// Reservation reads + creation are public so anonymous visitors can
// see the schedule and book; edits / cancels / deletes are gated to
// confirmed members in the group below. See docs/AUTH-AND-PERMISSIONS.md.
Route::get('reservations', [ReservationsController::class, 'index']);
// `mine` MUST be registered before `{id}` so it isn't captured as an id.
// It carries its own auth middleware (any authenticated user).
Route::get('reservations/mine', [ReservationsController::class, 'mine'])
    ->middleware('auth:sanctum');
Route::get('reservations/{id}', [ReservationsController::class, 'show']);
Route::post('reservations', [ReservationsController::class, 'store']);
Route::get('reservations/{id}/ics', [ReservationsController::class, 'ics']);

// Events: anonymous visitors see the list + a single event's metadata
// (title, description, date, location) only. Creating/editing events,
// the participant list, and the attached boats are confirmed-member only
// (see the member group below). docs/AUTH-AND-PERMISSIONS.md.
Route::get('events', [EventsController::class, 'index']);
Route::get('events/{id}', [EventsController::class, 'show']);

Route::apiResource('damages', DamagesController::class)
    ->parameters(['damages' => 'id']);
Route::get('damages/{id}/photo', [DamagesController::class, 'showPhoto']);
Route::post('damages/{id}/photo', [DamagesController::class, 'addPhoto']);
Route::delete('damages/{id}/photo', [DamagesController::class, 'removePhoto']);

// Reservation rules — public read so anonymous bookers can see them;
// PATCH is in the admin group below.
Route::get('reservation-rules', [ReservationRulesController::class, 'show']);

// Privacy policy — public read (also the URL Facebook Login requires);
// PATCH is in the admin group below.
Route::get('privacy-policy', [PrivacyPolicyController::class, 'show']);

/*
|--------------------------------------------------------------------------
| Auth required (MEMBER or ADMIN)
|--------------------------------------------------------------------------
| Token issued by /auth/login as `Authorization: Bearer <token>`.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // Own-account screen: change own password + manage linked social logins.
    Route::post('profile/change-password', [ProfileController::class, 'changePassword']);
    Route::get('profile/identities', [ProfileController::class, 'identities']);
    Route::delete('profile/identities/{provider}', [ProfileController::class, 'unlinkIdentity']);
    Route::get('profile/oauth/{provider}/link-url', [OAuthController::class, 'linkUrl']);

    // Audit log is technical and shown to everyone with a verified
    // account (including pending — useful for "did I really submit
    // that?" self-verification).
    Route::get('audit-logs', [AuditLogsController::class, 'index']);
});

/*
|--------------------------------------------------------------------------
| Confirmed member or admin (NOT pending, NOT anonymous)
|--------------------------------------------------------------------------
| Existing reservations belong to other paddlers; editing them is a
| meaningful action that requires a vetted account.
*/

Route::middleware(['auth:sanctum', 'member'])->group(function () {
    Route::patch('reservations/{id}', [ReservationsController::class, 'update']);
    Route::delete('reservations/{id}', [ReservationsController::class, 'destroy']);
    Route::patch('reservations/{id}/cancel', [ReservationsController::class, 'cancel']);

    // Event writes + the people/boats on an event are member-only. Anonymous
    // visitors can read the event list + metadata (public routes above) but
    // cannot edit, add, or see who/what is attached.
    Route::post('events', [EventsController::class, 'store']);
    Route::match(['put', 'patch'], 'events/{id}', [EventsController::class, 'update']);
    Route::delete('events/{id}', [EventsController::class, 'destroy']);
    Route::get('events/{id}/participants', [EventsController::class, 'listParticipants']);
    Route::post('events/{id}/participants', [EventsController::class, 'addParticipant']);
    Route::delete('events/{id}/participants/{participantId}', [EventsController::class, 'removeParticipant']);
    Route::post('events/{id}/reservations', [EventsController::class, 'attachResources']);
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
    Route::post('users/{id}/confirm', [UsersController::class, 'confirm']);
    Route::post('users/invite', [UsersController::class, 'invite']);
    Route::post('users/import', [UsersController::class, 'import']);

    Route::patch('reservation-rules', [ReservationRulesController::class, 'update']);
    Route::patch('privacy-policy', [PrivacyPolicyController::class, 'update']);

    Route::get('admin/usage-stats', [UsageStatsController::class, 'show']);

    Route::get('admin/export/database.json', [AdminDataController::class, 'exportDatabase']);
    Route::get('admin/export/reservations.csv', [AdminDataController::class, 'exportReservationsCsv']);
    Route::get('admin/export/resources.csv', [AdminDataController::class, 'exportResourcesCsv']);
    Route::post('admin/import/database', [AdminDataController::class, 'importDatabase']);
    Route::post('admin/reservations/purge', [AdminDataController::class, 'purgeReservations']);
});
