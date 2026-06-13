<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\DamageSeverity;
use App\Domain\Enums\DamageStatus;
use App\Domain\Enums\ResourceType;
use App\Models\Damage;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DamagePhotoApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeDamage(): Damage
    {
        $resource = Resource::create([
            'identifier' => 'K-PHOTO',
            'type' => ResourceType::WW_KAYAK,
            'name' => 'Test',
        ]);

        return Damage::create([
            'resourceId' => $resource->id,
            'description' => 'crack',
            'severity' => DamageSeverity::MODERATE,
            'status' => DamageStatus::REPORTED,
        ]);
    }

    public function test_upload_attaches_photo_and_returns_url(): void
    {
        Storage::fake('local');
        $damage = $this->makeDamage();

        $file = UploadedFile::fake()->create('damage.jpg', 50, 'image/jpeg');
        $response = $this->postJson("/api/v1/damages/{$damage->id}/photo", [
            'photo' => $file,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['id', 'photoUrl'])
            ->assertJsonPath('id', $damage->id);

        $url = $response->json('photoUrl');
        $this->assertStringContainsString("/api/v1/damages/{$damage->id}/photo", $url);
        Storage::disk('local')->assertExists("damages/{$damage->id}.jpg");
    }

    public function test_upload_rejects_non_image(): void
    {
        Storage::fake('local');
        $damage = $this->makeDamage();

        $file = UploadedFile::fake()->create('not-an-image.pdf', 100, 'application/pdf');
        $this->postJson("/api/v1/damages/{$damage->id}/photo", [
            'photo' => $file,
        ])->assertStatus(400)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_upload_rejects_oversize_files(): void
    {
        Storage::fake('local');
        $damage = $this->makeDamage();

        // 10 MB > 5 MB cap
        $file = UploadedFile::fake()->create('huge.jpg', 10 * 1024, 'image/jpeg');
        $this->postJson("/api/v1/damages/{$damage->id}/photo", [
            'photo' => $file,
        ])->assertStatus(400);
    }

    public function test_show_streams_the_photo(): void
    {
        Storage::fake('local');
        $damage = $this->makeDamage();
        $this->postJson("/api/v1/damages/{$damage->id}/photo", [
            'photo' => UploadedFile::fake()->create('p.jpg', 50, 'image/jpeg'),
        ])->assertOk();

        $response = $this->get("/api/v1/damages/{$damage->id}/photo");
        $response->assertOk();
        // Fake-image bytes from UploadedFile::fake()->create() are all-zero
        // so PHP detects 'application/x-empty' instead of 'image/jpeg'.
        // Just assert it's NOT a JSON / HTML error response.
        $ct = strtolower($response->headers->get('content-type') ?? '');
        $this->assertStringNotContainsString('json', $ct);
        $this->assertStringNotContainsString('html', $ct);
    }

    public function test_show_returns_404_when_damage_has_no_photo(): void
    {
        $damage = $this->makeDamage();
        $this->get("/api/v1/damages/{$damage->id}/photo")
            ->assertStatus(404);
    }

    public function test_remove_clears_photoPath_and_deletes_file(): void
    {
        Storage::fake('local');
        $damage = $this->makeDamage();
        $this->postJson("/api/v1/damages/{$damage->id}/photo", [
            'photo' => UploadedFile::fake()->create('p.jpg', 50, 'image/jpeg'),
        ])->assertOk();

        Storage::disk('local')->assertExists("damages/{$damage->id}.jpg");
        $this->deleteJson("/api/v1/damages/{$damage->id}/photo")->assertStatus(204);
        Storage::disk('local')->assertMissing("damages/{$damage->id}.jpg");

        $damage->refresh();
        $this->assertNull($damage->photoPath);
    }

    public function test_reupload_overwrites_previous_photo(): void
    {
        Storage::fake('local');
        $damage = $this->makeDamage();
        $this->postJson("/api/v1/damages/{$damage->id}/photo", [
            'photo' => UploadedFile::fake()->create('first.jpg', 100, 'image/jpeg'),
        ])->assertOk();
        Storage::disk('local')->assertExists("damages/{$damage->id}.jpg");

        $this->postJson("/api/v1/damages/{$damage->id}/photo", [
            'photo' => UploadedFile::fake()->create('second.jpg', 200, 'image/jpeg'),
        ])->assertOk();
        Storage::disk('local')->assertExists("damages/{$damage->id}.jpg");
    }
}
