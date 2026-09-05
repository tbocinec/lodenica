<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSiteConfigRequest;
use App\Services\SiteConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The club identity of this installation. Public read (the SPA paints the
 * header, links and feature switches before anyone logs in); admin write.
 * See docs/spec/13-site-configuration.md.
 */
class SiteController extends Controller
{
    public function __construct(private readonly SiteConfig $site) {}

    /** GET /api/v1/site — public, without adminEmail (SITE-002). */
    public function show(): JsonResponse
    {
        return new JsonResponse($this->site->publicPayload());
    }

    /** GET /api/v1/site/logo — public stream, 404 when no logo is set. */
    public function logo(): BinaryFileResponse
    {
        $path = $this->site->logoPath();
        if ($path === null) {
            throw new NotFoundHttpException('Logo nie je nastavené.');
        }

        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control' => 'public, max-age=86400',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** GET /api/v1/admin/site — everything, adminEmail included. */
    public function adminShow(): JsonResponse
    {
        return new JsonResponse($this->site->all());
    }

    /** PATCH /api/v1/admin/site — partial update, returns the resolved config. */
    public function update(UpdateSiteConfigRequest $request): JsonResponse
    {
        return new JsonResponse($this->site->update($request->validated()));
    }

    /**
     * POST /api/v1/admin/site/logo — multipart `logo` (png/jpg/webp ≤ 2 MB).
     * SVG is refused on purpose: it can carry scripts and the file is
     * served from the site's own origin.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $file = $request->file('logo');
        $ext = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'png');

        $this->deleteLogoFiles();
        Storage::disk('local')->putFileAs(SiteConfig::LOGO_DIR, $file, "logo.{$ext}");
        $this->site->setLogoPath(SiteConfig::LOGO_DIR."/logo.{$ext}");

        return new JsonResponse($this->site->all());
    }

    /** DELETE /api/v1/admin/site/logo */
    public function removeLogo(): JsonResponse
    {
        $this->deleteLogoFiles();
        $this->site->setLogoPath(null);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function deleteLogoFiles(): void
    {
        foreach (SiteConfig::LOGO_EXTENSIONS as $ext) {
            Storage::disk('local')->delete(SiteConfig::LOGO_DIR."/logo.{$ext}");
        }
    }
}
