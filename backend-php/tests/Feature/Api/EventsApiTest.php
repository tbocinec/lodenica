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
        // Event writes + attaching boats are confirmed-member only.
        $this->actingAsMember();

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

        $eventId = $eventResp->json('id');

        $this->postJson("/api/v1/events/{$eventId}/reservations", [
            'resourceIds' => [$kayak->id],
        ])
            ->assertCreated()
            ->assertJsonPath('0.eventId', $eventId)
            ->assertJsonPath('0.resourceId', $kayak->id);
    }

    public function test_add_and_list_participants(): void
    {
        $this->actingAsMember();

        $event = $this->postJson('/api/v1/events', [
            'title' => 'Tréning',
            'startsAt' => '2026-06-15T08:00:00Z',
            'endsAt' => '2026-06-15T10:00:00Z',
        ])->assertCreated()->json();

        $this->postJson("/api/v1/events/{$event['id']}/participants", [
            'name' => 'Ján Novák',
            'contact' => 'jan@example.com',
        ])
            ->assertCreated()
            ->assertJsonPath('name', 'Ján Novák');

        $this->getJson("/api/v1/events/{$event['id']}/participants")
            ->assertOk()
            ->assertJsonPath('0.name', 'Ján Novák');
    }

    public function test_update_event_time_window(): void
    {
        $this->actingAsMember();

        $event = $this->postJson('/api/v1/events', [
            'title' => 'X',
            'startsAt' => '2026-06-15T08:00:00Z',
            'endsAt' => '2026-06-15T10:00:00Z',
        ])->assertCreated()->json();

        $this->patchJson("/api/v1/events/{$event['id']}", [
            'endsAt' => '2026-06-15T11:00:00Z',
        ])
            ->assertOk()
            ->assertJsonPath('endsAt', '2026-06-15T11:00:00+00:00');
    }

    public function test_anonymous_can_list_and_view_events_but_not_write_or_see_attendees(): void
    {
        // Seed directly (no auth) so the rest of the test is genuinely
        // anonymous — exercising the public read / gated write split.
        $event = \App\Models\Event::create([
            'title' => 'Verejná udalosť',
            'description' => 'Popis',
            'location' => 'Devín',
            'startsAt' => '2026-06-15T08:00:00Z',
            'endsAt' => '2026-06-15T10:00:00Z',
        ]);
        \App\Models\EventParticipant::create([
            'eventId' => $event->id,
            'name' => 'Tajný Člen',
        ]);

        // List + metadata are public…
        $this->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonPath('items.0.title', 'Verejná udalosť');
        $this->getJson("/api/v1/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('location', 'Devín');

        // …but writing + the attendee list are member-gated.
        $this->postJson('/api/v1/events', [
            'title' => 'Hack',
            'startsAt' => '2026-06-15T08:00:00Z',
            'endsAt' => '2026-06-15T10:00:00Z',
        ])->assertStatus(401);
        $this->getJson("/api/v1/events/{$event->id}/participants")
            ->assertStatus(401);
        $this->patchJson("/api/v1/events/{$event->id}", ['title' => 'X'])
            ->assertStatus(401);
    }
}
