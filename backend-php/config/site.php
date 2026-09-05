<?php

/*
|--------------------------------------------------------------------------
| Site (client) configuration — install-time defaults
|--------------------------------------------------------------------------
|
| One codebase serves several clubs. Every value here is the DEFAULT for the
| matching field of the `site_config` settings row that an admin edits in
| the SPA (Administrácia → Systém → Nastavenia stránky). Resolution order,
| per field:
|
|     stored in settings  →  this file (from .env)  →  built-in default
|
| See App\Services\SiteConfig. The .env values are written by
| scripts/deploy-rezervacie.sh from the per-client .deploy-secrets.<client>
| file, so a fresh install looks right before anyone logs in.
|
*/

return [
    'name' => env('SITE_NAME'),
    'short_name' => env('SITE_SHORT_NAME'),
    'club_name' => env('SITE_CLUB_NAME'),
    'contact_email' => env('SITE_CONTACT_EMAIL'),
    // Where "new member waiting for approval" notices go. Falls back to
    // contact_email.
    'admin_email' => env('MAIL_ADMIN_ADDRESS'),
    'address' => env('SITE_ADDRESS'),
    'maps_url' => env('SITE_MAPS_URL'),
    'website_url' => env('SITE_WEBSITE_URL'),
    'rules_url' => env('SITE_RULES_URL'),
    'gdpr_notice_url' => env('SITE_GDPR_NOTICE_URL'),
    'gdpr_consent_url' => env('SITE_GDPR_CONSENT_URL'),
    'statutes_url' => env('SITE_STATUTES_URL'),
    'operator_notice' => env('SITE_OPERATOR_NOTICE'),
    'member_id_example' => env('SITE_MEMBER_ID_EXAMPLE'),
    'theme' => env('SITE_THEME'),

    'features' => [
        // Danube paddling traffic light (Bratislava + Devín gauges). Only
        // meaningful for clubs on the Danube — off unless switched on.
        'paddling_traffic_light' => env('SITE_FEATURE_TRAFFIC_LIGHT'),
        'expeditions' => env('SITE_FEATURE_EXPEDITIONS'),
    ],

    // First-install admin account (database/seeders/AdminSeeder.php).
    // Ignored once an admin exists.
    'admin' => [
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
        'name' => env('ADMIN_NAME'),
    ],

    // Demo boats/reservations outside `local` only when explicitly asked.
    'seed_demo_data' => (bool) env('SEED_DEMO_DATA', false),
];
