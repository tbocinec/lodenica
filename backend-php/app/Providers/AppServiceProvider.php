<?php

namespace App\Providers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Match the NestJS backend's response shape: return resources as
        // flat objects (`{ id, ... }`) rather than wrapped in `{ data: ... }`.
        // List endpoints already use Paginated::from() which returns the
        // flat `{ items, total, page, pageSize }` shape; this aligns the
        // single-resource and collection-resource paths with that.
        JsonResource::withoutWrapping();
    }
}
