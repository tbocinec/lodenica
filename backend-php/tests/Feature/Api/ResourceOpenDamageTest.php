<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\DamageSeverity;
use App\Domain\Enums\DamageStatus;
use App\Domain\Enums\ResourceType;
use App\Models\Damage;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Resources carry their worst OPEN damage inline, so every screen that
 * already has the resources store — the picker, the timeline, the
 * reservation form — can warn about a damaged boat without fetching
 * damages of its own.
 */
class ResourceOpenDamageTest extends TestCase
{
    use RefreshDatabase;

    private function boat(string $identifier): Resource
    {
        return Resource::create([
            'identifier' => $identifier,
            'type' => ResourceType::WW_KAYAK,
            'name' => 'Loď '.$identifier,
        ]);
    }

    private function damage(
        Resource $resource,
        DamageSeverity $severity,
        DamageStatus $status,
        string $description = 'prasklina',
    ): Damage {
        return Damage::create([
            'resourceId' => $resource->id,
            'description' => $description,
            'severity' => $severity,
            'status' => $status,
        ]);
    }

    public function test_resource_without_damage_reports_none(): void
    {
        $this->boat('K-CLEAN');

        $this->getJson('/api/v1/resources')
            ->assertOk()
            ->assertJsonPath('items.0.openDamage', null)
            ->assertJsonPath('items.0.openDamageCount', 0);
    }

    public function test_reported_damage_is_exposed_on_the_resource(): void
    {
        $boat = $this->boat('K-BROKEN');
        $d = $this->damage($boat, DamageSeverity::MODERATE, DamageStatus::REPORTED, 'diera v dne');

        $r = $this->getJson('/api/v1/resources')->assertOk();

        $r->assertJsonPath('items.0.openDamage.id', $d->id);
        $r->assertJsonPath('items.0.openDamage.status', 'REPORTED');
        $r->assertJsonPath('items.0.openDamage.severity', 'MODERATE');
        $r->assertJsonPath('items.0.openDamage.description', 'diera v dne');
        $r->assertJsonPath('items.0.openDamageCount', 1);
    }

    public function test_in_repair_damage_still_counts_as_open(): void
    {
        $boat = $this->boat('K-REPAIR');
        $this->damage($boat, DamageSeverity::MINOR, DamageStatus::IN_REPAIR);

        $this->getJson('/api/v1/resources')
            ->assertOk()
            ->assertJsonPath('items.0.openDamage.status', 'IN_REPAIR');
    }

    public function test_fixed_damage_is_not_reported_as_open(): void
    {
        $boat = $this->boat('K-FIXED');
        $this->damage($boat, DamageSeverity::CRITICAL, DamageStatus::FIXED);

        $this->getJson('/api/v1/resources')
            ->assertOk()
            ->assertJsonPath('items.0.openDamage', null)
            ->assertJsonPath('items.0.openDamageCount', 0);
    }

    /**
     * Severity is an enum, so "worst" can't come from an alphabetical sort
     * — CRITICAL must win over MODERATE and MINOR regardless of insert
     * order.
     */
    public function test_the_most_severe_open_damage_wins(): void
    {
        $boat = $this->boat('K-MANY');
        $this->damage($boat, DamageSeverity::MINOR, DamageStatus::REPORTED, 'škrabanec');
        $worst = $this->damage($boat, DamageSeverity::CRITICAL, DamageStatus::REPORTED, 'zlomené dno');
        $this->damage($boat, DamageSeverity::MODERATE, DamageStatus::IN_REPAIR, 'uvoľnený sedák');

        $this->getJson('/api/v1/resources')
            ->assertOk()
            ->assertJsonPath('items.0.openDamage.id', $worst->id)
            ->assertJsonPath('items.0.openDamage.severity', 'CRITICAL')
            ->assertJsonPath('items.0.openDamageCount', 3);
    }

    public function test_single_resource_endpoint_reports_open_damage_too(): void
    {
        $boat = $this->boat('K-ONE');
        $this->damage($boat, DamageSeverity::CRITICAL, DamageStatus::REPORTED);

        $this->getJson("/api/v1/resources/{$boat->id}")
            ->assertOk()
            ->assertJsonPath('openDamage.severity', 'CRITICAL');
    }

    /**
     * The club has ~84 boats and the picker loads them all at once — the
     * damage lookup must not turn into one query per resource.
     */
    public function test_listing_resources_does_not_run_a_query_per_resource(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $boat = $this->boat("K-N{$i}");
            $this->damage($boat, DamageSeverity::MINOR, DamageStatus::REPORTED);
        }

        DB::enableQueryLog();
        $this->getJson('/api/v1/resources?pageSize=50')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            12,
            $queries,
            "Zoznam lodí spustil {$queries} dotazov — vyzerá to na N+1 pri poškodeniach.",
        );
    }
}
