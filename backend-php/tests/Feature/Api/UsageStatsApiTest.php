<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\ResourceType;
use App\Models\Resource;
use App\Services\ReservationsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageStatsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_cannot_read(): void
    {
        $this->getJson('/api/v1/admin/usage-stats')->assertStatus(401);
    }

    public function test_member_cannot_read(): void
    {
        $this->actingAsMember();
        $this->getJson('/api/v1/admin/usage-stats')->assertStatus(403);
    }

    public function test_admin_gets_full_payload(): void
    {
        $this->seedFixture();
        $this->actingAsAdmin();
        $response = $this->getJson('/api/v1/admin/usage-stats');
        $response->assertOk()
            ->assertJsonStructure([
                'generatedAt',
                'window' => ['fromIso', 'toIso', 'days'],
                'totals' => ['reservationsAllTime', 'activeResources', 'openDamages'],
                'topResources' => [['resourceId', 'identifier', 'name', 'type', 'count', 'totalHours']],
                'coldResources' => [['resourceId', 'identifier', 'name', 'type', 'count']],
                'monthlyTrend' => [['monthIso', 'count']],
                'peakHours' => ['counts', 'max'],
                'damagesByType',
            ]);

        // Top resource is the one we booked 3 times.
        $this->assertSame('K-HOT', $response->json('topResources.0.identifier'));
        $this->assertSame(3, $response->json('topResources.0.count'));

        // Monthly trend has 7 buckets covering 6 months back through now.
        $this->assertGreaterThanOrEqual(6, count($response->json('monthlyTrend')));
    }

    public function test_peak_hours_is_24x7_grid(): void
    {
        $this->actingAsAdmin();
        $response = $this->getJson('/api/v1/admin/usage-stats');
        $counts = $response->json('peakHours.counts');
        $this->assertCount(7, $counts);
        foreach ($counts as $day) {
            $this->assertCount(24, $day);
        }
    }

    private function seedFixture(): void
    {
        $hot = Resource::create([
            'identifier' => 'K-HOT', 'type' => ResourceType::WW_KAYAK, 'name' => 'Hot kayak',
        ]);
        Resource::create([
            'identifier' => 'K-COLD', 'type' => ResourceType::SEA_KAYAK, 'name' => 'Cold kayak',
        ]);

        $svc = app(ReservationsService::class);
        $base = CarbonImmutable::now('UTC')->subDays(7)->setTime(9, 0);
        foreach ([0, 1, 2] as $i) {
            $svc->create([
                'resourceId' => $hot->id,
                'customerName' => "Booking $i",
                'startsAt' => $base->addDays($i)->toIso8601String(),
                'endsAt' => $base->addDays($i)->setTime(11, 0)->toIso8601String(),
            ]);
        }
    }
}
