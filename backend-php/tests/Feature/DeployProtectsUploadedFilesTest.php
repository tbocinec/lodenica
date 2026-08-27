<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regression guard for a production data loss.
 *
 * Uploaded photos (expeditions, damages, boats) all live on the `local`
 * disk. Laravel 11 moved that disk's root from storage/app to
 * storage/app/private, but the deploy script kept excluding the OLD paths
 * — storage/app/expeditions/*, storage/app/damages/*, storage/app/resources/*
 * — none of which exist any more. `lftp mirror -R --delete` therefore
 * deleted every uploaded photo on the server on EVERY deploy.
 *
 * This test ties the deploy script's exclusions to the disk config, so the
 * two can't drift apart again.
 */
class DeployProtectsUploadedFilesTest extends TestCase
{
    private function deployScript(): string
    {
        $path = base_path('../scripts/deploy-rezervacie.sh');
        if (!is_readable($path)) {
            // The deployed copy of the app has no scripts/ directory.
            $this->markTestSkipped('deploy-rezervacie.sh not available here.');
        }

        return (string) file_get_contents($path);
    }

    /** Disk root as a path relative to the Laravel app root. */
    private function relativeDiskRoot(string $disk): string
    {
        $root = (string) config("filesystems.disks.{$disk}.root");

        return ltrim(str_replace(base_path(), '', $root), '/');
    }

    public function test_rsync_stage_keeps_local_disk_uploads_out_of_the_bundle(): void
    {
        $relative = $this->relativeDiskRoot('local');

        $this->assertStringContainsString(
            "--exclude='{$relative}/*'",
            $this->deployScript(),
            "Deploy skript nevylučuje '{$relative}/*' z rsync — nahrané súbory by sa dostali do balíka.",
        );
    }

    /**
     * The assertion that would have prevented the loss: without this
     * exclusion `mirror --delete` wipes the directory on the server.
     */
    public function test_mirror_delete_cannot_wipe_local_disk_uploads(): void
    {
        $relative = $this->relativeDiskRoot('local');

        $this->assertStringContainsString(
            "--exclude-glob '{$relative}/*'",
            $this->deployScript(),
            "Deploy skript nechráni '{$relative}/*' pred `mirror --delete` — ".
            'každý deploy zmaže nahrané fotky na serveri.',
        );
    }

    public function test_mirror_delete_cannot_wipe_public_disk_uploads(): void
    {
        $relative = $this->relativeDiskRoot('public');

        $this->assertStringContainsString(
            "--exclude-glob '{$relative}/*'",
            $this->deployScript(),
            "Deploy skript nechráni '{$relative}/*' pred `mirror --delete`.",
        );
    }

    /**
     * The paths that used to be excluded no longer exist. Leaving them in
     * reads as protection that isn't there.
     */
    public function test_the_stale_pre_laravel_11_paths_are_gone(): void
    {
        $script = $this->deployScript();

        foreach (['storage/app/expeditions', 'storage/app/damages', 'storage/app/resources'] as $stale) {
            $this->assertStringNotContainsString(
                "{$stale}/*",
                $script,
                "'{$stale}/*' už nie je reálna cesta (disk 'local' má koreň storage/app/private) — ".
                'vyzerá to ako ochrana, ale nechráni nič.',
            );
        }
    }
}
