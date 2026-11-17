<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Small JPEG thumbnails of uploaded boat photos, generated on first request
 * and cached next to the originals on the `local` disk.
 *
 * Uploads are stored as sent (up to 5 MB), so a list of 18 boats would pull
 * ~90 MB if every row showed the original. Needs the GD extension; when it is
 * missing — or anything goes wrong — callers get null and serve the original
 * instead, so a thumbnail can never make a photo disappear.
 *
 * Cache files live under `resources/thumbs/` on the `local` disk, i.e. inside
 * storage/app/private, which the deploy never touches (see
 * DeployProtectsUploadedFilesTest).
 */
class PhotoThumbnail
{
    public const DIR = 'resources/thumbs';

    public const MIN_WIDTH = 48;

    public const MAX_WIDTH = 800;

    /**
     * Absolute path of a thumbnail no wider/taller than $width, or null when
     * one can't be produced.
     */
    public static function for(string $resourceId, string $photoPath, int $width): ?string
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) {
            return null;
        }

        $width = max(self::MIN_WIDTH, min(self::MAX_WIDTH, $width));
        $disk = Storage::disk('local');
        $source = $disk->path($photoPath);
        if (!is_file($source)) {
            return null;
        }

        // mtime in the name: a re-uploaded photo gets a fresh thumbnail.
        $relative = sprintf('%s/%s-%d-%d.jpg', self::DIR, $resourceId, $width, (int) filemtime($source));
        $target = $disk->path($relative);
        if (is_file($target)) {
            return $target;
        }

        try {
            $bytes = self::render($source, $width);
            if ($bytes === null) {
                return null;
            }
            $disk->makeDirectory(self::DIR);
            // Write + rename so a concurrent request never reads a half file.
            $tmp = $target.'.'.bin2hex(random_bytes(4)).'.tmp';
            if (file_put_contents($tmp, $bytes) === false || !rename($tmp, $target)) {
                @unlink($tmp);

                return null;
            }

            return $target;
        } catch (Throwable) {
            return null;
        }
    }

    /** Drop every cached thumbnail of a resource (photo replaced/removed). */
    public static function purge(string $resourceId): void
    {
        $disk = Storage::disk('local');
        foreach ($disk->files(self::DIR) as $file) {
            if (str_starts_with(basename($file), $resourceId.'-')) {
                $disk->delete($file);
            }
        }
    }

    private static function render(string $source, int $maxSide): ?string
    {
        $info = @getimagesize($source);
        if ($info === false) {
            return null;
        }
        [$w, $h] = $info;
        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default => false,
        };
        if ($image === false) {
            return null;
        }

        // Phone photos carry their rotation in EXIF; GD ignores it.
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $orientation = @exif_read_data($source)['Orientation'] ?? 1;
            $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
            if ($angle !== 0 && ($rotated = imagerotate($image, $angle, 0)) !== false) {
                $image = $rotated;
                if (abs($angle) === 90) {
                    [$w, $h] = [$h, $w];
                }
            }
        }

        $scale = min(1.0, $maxSide / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));

        $thumb = imagecreatetruecolor($tw, $th);
        // Flatten transparency (PNG/WebP) onto white — JPEG has no alpha.
        imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $tw, $th, imagesx($image), imagesy($image));

        ob_start();
        imagejpeg($thumb, null, 80);
        $bytes = ob_get_clean();

        return $bytes === false || $bytes === '' ? null : $bytes;
    }
}
