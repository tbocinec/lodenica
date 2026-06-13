<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\ReservationStatus;
use App\Domain\Enums\ResourceType;
use App\Models\Reservation;
use App\Models\Resource;
use App\Services\ReservationsService;
use App\Services\ResourcesService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDataApiTest extends TestCase
{
    use RefreshDatabase;

    /* ──────────────  Auth gating  ────────────── */

    public function test_all_endpoints_require_auth(): void
    {
        $this->getJson('/api/v1/admin/export/database.json')->assertStatus(401);
        $this->get('/api/v1/admin/export/reservations.csv')->assertStatus(401);
        $this->get('/api/v1/admin/export/resources.csv')->assertStatus(401);
        $this->postJson('/api/v1/admin/import/database', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/reservations/purge', [])->assertStatus(401);
    }

    public function test_member_cannot_use_admin_data(): void
    {
        $this->actingAsMember();
        $this->getJson('/api/v1/admin/export/database.json')->assertStatus(403);
        $this->postJson('/api/v1/admin/reservations/purge', ['confirmation' => 'VYMAZAŤ'])
            ->assertStatus(403);
    }

    /* ──────────────  Exports  ────────────── */

    public function test_database_export_returns_all_tables(): void
    {
        $this->actingAsAdmin();
        $this->seedFixture();

        $r = $this->getJson('/api/v1/admin/export/database.json');
        $r->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="lodenica-backup-'.date('Y-m-d').'.json"')
            ->assertJsonStructure([
                'exportedAt', 'version',
                'tables' => [
                    'resources', 'events', 'reservations',
                    'event_participants', 'damages', 'audit_logs', 'settings',
                ],
            ])
            ->assertJsonPath('version', 1);

        $this->assertCount(2, $r->json('tables.resources'));
        $this->assertGreaterThanOrEqual(1, count($r->json('tables.reservations')));
    }

    public function test_resources_csv_export_streams_with_bom(): void
    {
        $this->actingAsAdmin();
        $this->seedFixture();

        $r = $this->get('/api/v1/admin/export/resources.csv');
        $r->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=utf-8');

        $body = $r->streamedContent();
        // UTF-8 BOM so Excel renders Slovak diacritics on open
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('identifier', $body);
        $this->assertStringContainsString('K-HOT', $body);
        $this->assertStringContainsString('K-COLD', $body);
    }

    public function test_reservations_csv_export_includes_resource_columns(): void
    {
        $this->actingAsAdmin();
        $this->seedFixture();

        $r = $this->get('/api/v1/admin/export/reservations.csv');
        $body = $r->streamedContent();
        $this->assertStringContainsString('resourceIdentifier', $body);
        $this->assertStringContainsString('K-HOT', $body);
    }

    /* ──────────────  Purge  ────────────── */

    public function test_purge_requires_confirmation(): void
    {
        $this->actingAsAdmin();
        $this->seedFixture();
        $this->postJson('/api/v1/admin/reservations/purge', [])
            ->assertStatus(400)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->postJson('/api/v1/admin/reservations/purge', ['confirmation' => 'oops'])
            ->assertStatus(400);
    }

    public function test_purge_all_reservations_with_correct_confirmation(): void
    {
        $this->actingAsAdmin();
        $this->seedFixture();
        $before = Reservation::count();
        $this->assertGreaterThan(0, $before);

        $r = $this->postJson('/api/v1/admin/reservations/purge', ['confirmation' => 'VYMAZAŤ']);
        $r->assertOk()->assertJsonPath('deleted', $before);
        $this->assertSame(0, Reservation::count());
    }

    public function test_purge_only_old_reservations(): void
    {
        $this->actingAsAdmin();
        $resource = Resource::create([
            'identifier' => 'K-AGE', 'type' => ResourceType::WW_KAYAK, 'name' => 'Aged',
        ]);
        // Old (2 years back)
        $old = Reservation::create([
            'resourceId' => $resource->id,
            'customerName' => 'Old',
            'startsAt' => CarbonImmutable::now()->subYears(2)->subHours(2),
            'endsAt' => CarbonImmutable::now()->subYears(2),
            'status' => ReservationStatus::CONFIRMED,
        ]);
        // Recent (yesterday)
        $recent = Reservation::create([
            'resourceId' => $resource->id,
            'customerName' => 'Recent',
            'startsAt' => CarbonImmutable::now()->subDay()->subHours(2),
            'endsAt' => CarbonImmutable::now()->subDay(),
            'status' => ReservationStatus::CONFIRMED,
        ]);

        $this->actingAsAdmin();
        $r = $this->postJson('/api/v1/admin/reservations/purge', [
            'confirmation' => 'VYMAZAŤ',
            'olderThanDays' => 365,
        ]);
        $r->assertOk()->assertJsonPath('deleted', 1);
        $this->assertNull(Reservation::find($old->id));
        $this->assertNotNull(Reservation::find($recent->id));
    }

    /* ──────────────  Import  ────────────── */

    public function test_import_requires_confirmation_and_payload(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/import/database', [])
            ->assertStatus(400);
        $this->postJson('/api/v1/admin/import/database', [
            'confirmation' => 'VYMAZAŤ A OBNOVIŤ',
        ])->assertStatus(400);
    }

    public function test_import_wipes_and_restores_from_export(): void
    {
        $this->actingAsAdmin();
        $this->seedFixture();

        $beforeResources = Resource::count();
        $beforeReservations = Reservation::count();
        $this->assertGreaterThan(0, $beforeResources);
        $this->assertGreaterThan(0, $beforeReservations);

        // Capture an export of the current state.
        $export = $this->getJson('/api/v1/admin/export/database.json')->json();

        // Round-trip: re-import the same payload. The endpoint wipes
        // everything in the right FK order and re-inserts.
        $r = $this->postJson('/api/v1/admin/import/database', [
            'confirmation' => 'VYMAZAŤ A OBNOVIŤ',
            'tables' => $export['tables'],
        ]);
        $r->assertOk();

        // After round-trip the row counts match exactly.
        $this->assertSame($beforeResources, Resource::count());
        $this->assertSame($beforeReservations, Reservation::count());
        $this->assertNotNull(Resource::where('identifier', 'K-HOT')->first());
    }

    /* ──────────────  Fixture  ────────────── */

    private function seedFixture(): void
    {
        $resources = app(ResourcesService::class);
        $reservations = app(ReservationsService::class);

        $hot = $resources->create(['identifier' => 'K-HOT', 'type' => ResourceType::WW_KAYAK, 'name' => 'Hot']);
        $resources->create(['identifier' => 'K-COLD', 'type' => ResourceType::SEA_KAYAK, 'name' => 'Cold']);

        $reservations->create([
            'resourceId' => $hot->id,
            'customerName' => 'Tomáš',
            'startsAt' => '2099-04-12T08:00:00Z',
            'endsAt' => '2099-04-12T10:00:00Z',
        ]);
    }
}
