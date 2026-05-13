<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\ResourceType;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_event_and_attach_resources(): void
    {
        $kayak = Resource::create([
            'identifier' => 'K-1', 'type' => ResourceType::WW_KAYAK, 'name' => 'K1',
        ]);

        $eventResp = $this->postJson('/api/v1/events', [
            'title' => 'Splav Dunaja',
            'description' => 'Klubový splav.',
            'location' => 'Devín',
            'startsAt' => '2026-06-15T08:00:00Z',
            'endsAt' => '2026-06-15T18:00:00Z',
        ])->assertCreated();

        $eventId = $eventResp->json('data.id');

        $this->postJson("/api/v1/events/{$eventId}/reservations", [
            'resourceIds' => [$kayak->id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.0.eventId', $eventId)
            ->assertJsonPath('data.0.resourceId', $kayak->id);
    }

    public function test_add_and_list_participants(): void
    {
        $event = $this->postJson('/api/v1/events', [
            'title' => 'Tréning',
            'startsAt' => '2026-06-15T08:00:00Z',
            'endsAt' => '2026-06-15T10:00:00Z',
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/events/{$event['id']}/participants", [
            'name' => 'Ján Novák',
            'contact' => 'jan@example.com',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ján Novák');

        $this->getJson("/api/v1/events/{$event['id']}/participants")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Ján Novák');
    }

    public function test_update_event_time_window(): void
    {
        $event = $this->postJson('/api/v1/events', [
            'title' => 'X',
            'startsAt' => '2026-06-15T08:00:00Z',
            'endsAt' => '2026-06-15T10:00:00Z',
        ])->assertCreated()->json('data');

        $this->patchJson("/api/v1/events/{$event['id']}", [
            'endsAt' => '2026-06-15T11:00:00Z',
        ])
            ->assertOk()
            ->assertJsonPath('data.endsAt', '2026-06-15T11:00:00+00:00');
    }
}
