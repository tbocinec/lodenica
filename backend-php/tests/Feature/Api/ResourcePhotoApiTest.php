<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\ResourceType;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResourcePhotoApiTest extends TestCase
{
    use RefreshDatabase;

    private Resource $boat;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->boat = Resource::create([
            'identifier' => 'K-1', 'type' => ResourceType::WW_KAYAK, 'name' => 'Kayak 1',
        ]);
    }

    public function test_admin_can_upload_photo_and_it_is_served(): void
    {
        $this->actingAsAdmin();

        // create() (not image()) — the CI/box may lack GD; a fake file with
        // an image mime still satisfies the `image`/`mimes` rules.
        $file = UploadedFile::fake()->create('boat.jpg', 200, 'image/jpeg');

        $resp = $this->post("/api/v1/resources/{$this->boat->id}/photo", ['photo' => $file])
            ->assertOk()
            ->json();

        $this->assertNotNull($resp['photoUrl']);
        Storage::disk('local')->assertExists("resources/{$this->boat->id}.jpg");

        // Public GET serves it.
        $this->get("/api/v1/resources/{$this->boat->id}/photo")->assertOk();
    }

    public function test_photo_upload_is_admin_only(): void
    {
        $this->actingAsMember();
        $file = UploadedFile::fake()->create('boat.jpg', 200, 'image/jpeg');
        $this->post("/api/v1/resources/{$this->boat->id}/photo", ['photo' => $file])
            ->assertStatus(403);
    }

    public function test_photo_upload_requires_auth(): void
    {
        $file = UploadedFile::fake()->create('boat.jpg', 200, 'image/jpeg');
        $this->post("/api/v1/resources/{$this->boat->id}/photo", ['photo' => $file])
            ->assertStatus(401);
    }

    public function test_admin_can_remove_photo(): void
    {
        $this->actingAsAdmin();
        $file = UploadedFile::fake()->create('boat.jpg', 200, 'image/jpeg');
        $this->post("/api/v1/resources/{$this->boat->id}/photo", ['photo' => $file])->assertOk();

        $this->delete("/api/v1/resources/{$this->boat->id}/photo")->assertNoContent();

        Storage::disk('local')->assertMissing("resources/{$this->boat->id}.jpg");
        $this->getJson("/api/v1/resources/{$this->boat->id}")
            ->assertOk()
            ->assertJsonPath('photoUrl', null);
    }

    public function test_show_photo_404_when_none(): void
    {
        $this->get("/api/v1/resources/{$this->boat->id}/photo")->assertStatus(404);
    }
}
