<?php

namespace App\Providers;

use App\Services\SiteConfig;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\View;
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

        // The e-mail layout's header and <title> carry the installation's
        // name. A composer keeps every template from having to pass it.
        View::composer('emails.layout', function (\Illuminate\View\View $view): void {
            $view->with('siteName', app(SiteConfig::class)->siteName());
        });
    }
}
