<?php

return [

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    */

    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    |
    | Published (rather than left to the framework default) for ONE reason:
    | Laravel's default wraps this in realpath(), which returns FALSE when
    | the directory doesn't exist yet. Blade then throws
    | "Please provide a valid cache path." on the very first render.
    |
    | That bit us in production. The SFTP deploy ships
    | storage/framework/views as an empty directory, empty directories don't
    | survive the lftp mirror, and `config:cache` baked the FALSE in. Nothing
    | looked broken — the API returns JSON and the SPA is a static file, so
    | e-mails are the only thing in this app that renders Blade. Every
    | outgoing mail died instantly while the rest of the site was fine.
    |
    | Handing Blade a plain string instead lets it create the directory on
    | first use (see Compiler::ensureCompiledDirectoryExists), so a missing
    | directory heals itself rather than taking the mail system down.
    */

    'compiled' => env('VIEW_COMPILED_PATH', storage_path('framework/views')),

];
