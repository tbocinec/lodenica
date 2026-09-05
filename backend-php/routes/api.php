<?php

use App\Http\Controllers\Api\AdminDataController;
use App\Http\Controllers\Api\AuditLogsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\DamageCommentsController;
use App\Http\Controllers\Api\DamagesController;
use App\Http\Controllers\Api\MemberRosterController;
use App\Http\Controllers\Api\EventsController;
use App\Http\Controllers\Api\ExpeditionsController;
use App\Http\Controllers\Api\FaqController;
use App\Http\Controllers\Api\OAuthController;
use App\Http\Controllers\Api\PaddlingTrafficLightController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReservationApprovalsController;
use App\Http\Controllers\Api\ReservationRulesController;
use App\Http\Controllers\Api\ReservationsController;
use App\Http\Controllers\Api\ResourcesController;
use App\Http\Controllers\Api\UsageController;
use App\Http\Controllers\Api\MailDiagnosticsController;
use App\Http\Controllers\Api\MailNotificationsController;
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
// Finalises a first-time social registration after GDPR consents.
Route::post('auth/oauth/complete', [OAuthController::class, 'complete']);

Route::get('availability/dashboard', [AvailabilityController::class, 'dashboard']);

// Paddling traffic light (proxied + cached from dunajcik.sk). Public.
Route::get('paddling-traffic-light', [PaddlingTrafficLightController::class, 'show']);

// Expedition photo streaming is public-by-URL (UUIDs) so <img> tags can load
// it without the bearer token, like damage/resource photos. The expedition
// data itself stays member-gated (see the member group below).
Route::get('expeditions/{id}/photos/{photoId}', [ExpeditionsController::class, 'showPhoto']);

// Anonymous usage beacon (pageview / visit). No PII collected. Tightly
// throttled — a real client pings ~once per page load, so 20/min/IP is
// generous while limiting how much a script can inflate the counters.
Route::post('usage/visit', [UsageController::class, 'visit'])->middleware('throttle:20,1');

// Read-only resource browsing is public; writes are admin-only (see group below).
Route::get('resources', [ResourcesController::class, 'index']);
Route::get('resources/{id}', [ResourcesController::class, 'show']);
// Resource photo — public read (shown in the boat detail); upload/remove
// are admin-only (see admin group below).
Route::get('resources/{id}/photo', [ResourcesController::class, 'showPhoto']);

// Reservation reads + creation are public so anonymous visitors can
// see the schedule and book; edits / cancels / deletes are gated to
// confirmed members in the group below. See docs/AUTH-AND-PERMISSIONS.md.
Route::get('reservations', [ReservationsController::class, 'index']);
// `mine` MUST be registered before `{id}` so it isn't captured as an id.
// It carries its own auth middleware (any authenticated user).
Route::get('reservations/mine', [ReservationsController::class, 'mine'])
    ->middleware('auth:sanctum');
// `approvals` likewise MUST precede `{id}`. Confirmed members only; whether
// the caller may decide a given reservation is enforced in the service.
Route::get('reservations/approvals', [ReservationApprovalsController::class, 'index'])
    ->middleware(['auth:sanctum', 'member']);
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

// Q&A / FAQ — public read; PATCH is in the admin group below.
Route::get('faq', [FaqController::class, 'show']);

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

    // Own e-mail switches for the user-configurable notifications (REZ-062).
    Route::get('profile/notifications', [ProfileController::class, 'notifications']);
    Route::patch('profile/notifications', [ProfileController::class, 'updateNotifications']);

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
    // Damage comments — confirmed members only to READ as well as write,
    // because every comment is signed with its author's name.
    Route::get('damages/{id}/comments', [DamageCommentsController::class, 'index']);
    Route::post('damages/{id}/comments', [DamageCommentsController::class, 'store']);
    Route::delete('damages/{id}/comments/{commentId}', [DamageCommentsController::class, 'destroy']);

    Route::patch('reservations/{id}', [ReservationsController::class, 'update']);
    Route::delete('reservations/{id}', [ReservationsController::class, 'destroy']);
    Route::patch('reservations/{id}/cancel', [ReservationsController::class, 'cancel']);

    // Approval workflow (REZ-054…): approvers and admins decide waiting requests.
    Route::post('reservations/{id}/approve', [ReservationApprovalsController::class, 'approve']);
    Route::post('reservations/{id}/reject', [ReservationApprovalsController::class, 'reject']);

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

    // Expedície — members' world map of paddled places. Read + create for any
    // member; edit/delete an entry or its photos is author-or-admin (enforced
    // in the controller).
    Route::get('expeditions', [ExpeditionsController::class, 'index']);
    Route::post('expeditions', [ExpeditionsController::class, 'store']);
    Route::patch('expeditions/{id}', [ExpeditionsController::class, 'update']);
    Route::delete('expeditions/{id}', [ExpeditionsController::class, 'destroy']);
    Route::post('expeditions/{id}/photos', [ExpeditionsController::class, 'addPhoto']);
    Route::delete('expeditions/{id}/photos/{photoId}', [ExpeditionsController::class, 'removePhoto']);
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
    Route::post('resources/{id}/photo', [ResourcesController::class, 'addPhoto']);
    Route::delete('resources/{id}/photo', [ResourcesController::class, 'removePhoto']);

    Route::apiResource('users', UsersController::class)
        ->parameters(['users' => 'id']);
    Route::post('users/{id}/confirm', [UsersController::class, 'confirm']);
    Route::post('users/invite', [UsersController::class, 'invite']);
    Route::post('users/import', [UsersController::class, 'import']);
    Route::delete('users/{id}/identities/{provider}', [UsersController::class, 'unlinkIdentity']);

    // Member roster ("číselník") — drives self-registration auto-approval.
    Route::get('member-roster', [MemberRosterController::class, 'index']);
    Route::post('member-roster', [MemberRosterController::class, 'store']);
    Route::post('member-roster/import', [MemberRosterController::class, 'import']);
    Route::patch('member-roster/{id}', [MemberRosterController::class, 'update']);
    Route::delete('member-roster/{id}', [MemberRosterController::class, 'destroy']);

    Route::patch('reservation-rules', [ReservationRulesController::class, 'update']);
    Route::patch('faq', [FaqController::class, 'update']);

    Route::get('admin/usage-stats', [UsageStatsController::class, 'show']);

    // Mail diagnostics + the per-notification on/off switches. The test
    // send is throttled so the club mailbox can't be used as a relay by a
    // stuck browser tab.
    Route::get('admin/mail/config', [MailDiagnosticsController::class, 'config']);
    Route::post('admin/mail/test', [MailDiagnosticsController::class, 'test'])
        ->middleware('throttle:5,1');
    Route::get('admin/mail/log', [MailDiagnosticsController::class, 'log']);
    Route::get('admin/mail/notifications', [MailNotificationsController::class, 'index']);
    Route::patch('admin/mail/notifications', [MailNotificationsController::class, 'update']);

    Route::get('admin/export/database.json', [AdminDataController::class, 'exportDatabase']);
    Route::get('admin/export/reservations.csv', [AdminDataController::class, 'exportReservationsCsv']);
    Route::get('admin/export/resources.csv', [AdminDataController::class, 'exportResourcesCsv']);
    Route::get('admin/export/members.csv', [AdminDataController::class, 'exportMembersCsv']);
    Route::post('admin/import/database', [AdminDataController::class, 'importDatabase']);
    Route::post('admin/reservations/purge', [AdminDataController::class, 'purgeReservations']);
});
