<?php

namespace Tests\Feature\Api;

use App\Models\Expedition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpeditionsApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Dunajský maratón',
            'place' => 'Dunaj, Bratislava',
            'latitude' => 48.14,
            'longitude' => 17.10,
            'year' => 2024,
            'waterType' => 'river',
            'participants' => 'Janko, Marka',
            'publishConsent' => true,
        ], $overrides);
    }

    public function test_anonymous_cannot_list(): void
    {
        $this->getJson('/api/v1/expeditions')->assertStatus(401);
    }

    public function test_pending_cannot_list(): void
    {
        $this->actingAsPending();
        $this->getJson('/api/v1/expeditions')->assertStatus(403);
    }

    public function test_member_can_create_and_list(): void
    {
        $this->actingAsMember();

        $this->postJson('/api/v1/expeditions', $this->payload())
            ->assertCreated()
            ->assertJsonPath('title', 'Dunajský maratón')
            ->assertJsonPath('canEdit', true);

        $this->getJson('/api/v1/expeditions')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_create_stores_multiple_countries(): void
    {
        $this->actingAsMember();

        $this->postJson('/api/v1/expeditions', $this->payload([
            'countries' => ['Slovensko', 'Rakúsko', 'Maďarsko'],
        ]))->assertCreated()
            ->assertJsonCount(3, 'countries')
            ->assertJsonPath('countries.1', 'Rakúsko');
    }

    public function test_create_stores_route_polyline(): void
    {
        $this->actingAsMember();

        $this->postJson('/api/v1/expeditions', $this->payload([
            'route' => [[48.14, 17.10], [48.20, 17.20], [48.25, 17.28]],
        ]))->assertCreated()
            ->assertJsonCount(3, 'route')
            ->assertJsonPath('route.0.0', 48.14);
    }

    public function test_route_rejects_malformed_points(): void
    {
        $this->actingAsMember();
        $this->postJson('/api/v1/expeditions', $this->payload([
            'route' => [[48.14], [999, 17.2]], // wrong size + out of range
        ]))->assertStatus(400);
    }

    public function test_create_requires_publish_consent(): void
    {
        $this->actingAsMember();
        $p = $this->payload();
        unset($p['publishConsent']);

        $this->postJson('/api/v1/expeditions', $p)
            ->assertStatus(400)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_create_requires_coordinates(): void
    {
        $this->actingAsMember();
        $p = $this->payload();
        unset($p['latitude']);

        $this->postJson('/api/v1/expeditions', $p)->assertStatus(400);
    }

    public function test_non_author_member_cannot_edit(): void
    {
        $author = $this->actingAsMember();
        $exp = Expedition::create([
            'title' => 'X', 'place' => 'Y', 'latitude' => 1, 'longitude' => 2,
            'publishConsent' => true, 'createdById' => $author->id,
        ]);

        $this->actingAsMember(); // now a different member
        $this->patchJson("/api/v1/expeditions/{$exp->id}", ['title' => 'Hacked'])
            ->assertStatus(403);
    }

    public function test_author_can_edit_and_admin_can_delete(): void
    {
        $author = $this->actingAsMember();
        $exp = Expedition::create([
            'title' => 'Mine', 'place' => 'Y', 'latitude' => 1, 'longitude' => 2,
            'publishConsent' => true, 'createdById' => $author->id,
        ]);

        $this->patchJson("/api/v1/expeditions/{$exp->id}", ['title' => 'Updated'])
            ->assertOk()->assertJsonPath('title', 'Updated');

        $this->actingAsAdmin();
        $this->deleteJson("/api/v1/expeditions/{$exp->id}")->assertNoContent();
        $this->assertDatabaseMissing('expeditions', ['id' => $exp->id]);
    }

    public function test_member_can_upload_photo_and_it_is_public(): void
    {
        Storage::fake('local');
        $author = $this->actingAsMember();
        $exp = Expedition::create([
            'title' => 'Photo trip', 'place' => 'Y', 'latitude' => 1, 'longitude' => 2,
            'publishConsent' => true, 'createdById' => $author->id,
        ]);

        $resp = $this->postJson("/api/v1/expeditions/{$exp->id}/photos", [
            'photo' => UploadedFile::fake()->create('trip.jpg', 200, 'image/jpeg'),
        ])->assertOk()->assertJsonCount(1, 'photos');

        $photoId = $resp->json('photos.0.id');
        $this->assertDatabaseHas('expedition_photos', ['id' => $photoId, 'expeditionId' => $exp->id]);

        // The streaming route is public (registered outside the member group)
        // so an <img> tag can load it without the bearer token.
        $this->get("/api/v1/expeditions/{$exp->id}/photos/{$photoId}")->assertOk();
    }
}
