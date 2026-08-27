<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\DamageSeverity;
use App\Domain\Enums\DamageStatus;
use App\Domain\Enums\ResourceType;
use App\Models\Damage;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who reported a damage and who is fixing it. Both are personal names, so
 * they follow the same rule as reservation customer names: visible to
 * confirmed members, hidden from anonymous visitors and PENDING accounts.
 */
class DamagePeopleFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function boat(): Resource
    {
        return Resource::create([
            'identifier' => 'K-DMG', 'type' => ResourceType::WW_KAYAK, 'name' => 'Testovacia',
        ]);
    }

    private function damage(Resource $boat, array $over = []): Damage
    {
        return Damage::create(array_merge([
            'resourceId' => $boat->id,
            'description' => 'prasklina',
            'severity' => DamageSeverity::MODERATE,
            'status' => DamageStatus::REPORTED,
        ], $over));
    }

    /* ──────────────  Riešiteľ  ────────────── */

    public function test_assignee_can_be_set_on_create(): void
    {
        $this->actingAsMember();
        $boat = $this->boat();

        $this->postJson('/api/v1/damages', [
            'resourceId' => $boat->id,
            'description' => 'prasklina',
            'severity' => 'MODERATE',
            'assigneeName' => 'Jozef Servis',
        ])->assertCreated()->assertJsonPath('assigneeName', 'Jozef Servis');
    }

    public function test_assignee_can_be_changed_on_update(): void
    {
        $this->actingAsMember();
        $d = $this->damage($this->boat());

        $this->patchJson("/api/v1/damages/{$d->id}", ['assigneeName' => 'Externý servis'])
            ->assertOk()
            ->assertJsonPath('assigneeName', 'Externý servis');
    }

    public function test_assignee_can_be_cleared(): void
    {
        $this->actingAsMember();
        $d = $this->damage($this->boat(), ['assigneeName' => 'Niekto']);

        $this->patchJson("/api/v1/damages/{$d->id}", ['assigneeName' => null])
            ->assertOk()
            ->assertJsonPath('assigneeName', null);
    }

    /* ──────────────  Nahlásil  ────────────── */

    public function test_reporter_defaults_to_the_logged_in_user(): void
    {
        $user = $this->actingAsMember(['name' => 'Anna Členka']);
        $boat = $this->boat();

        $this->postJson('/api/v1/damages', [
            'resourceId' => $boat->id,
            'description' => 'diera',
            'severity' => 'MINOR',
        ])->assertCreated()->assertJsonPath('reportedByName', 'Anna Členka');

        $this->assertSame('Anna Členka', Damage::query()->latest('createdAt')->first()->reportedByName);
        $this->assertNotNull($user->id);
    }

    public function test_an_explicit_reporter_wins_over_the_logged_in_user(): void
    {
        $this->actingAsMember(['name' => 'Anna Členka']);
        $boat = $this->boat();

        $this->postJson('/api/v1/damages', [
            'resourceId' => $boat->id,
            'description' => 'diera',
            'severity' => 'MINOR',
            'reportedByName' => 'Nahlásil to tréner',
        ])->assertCreated()->assertJsonPath('reportedByName', 'Nahlásil to tréner');
    }

    /** Reporting a damage stays open to anyone — nothing to attribute then. */
    public function test_anonymous_report_leaves_the_reporter_empty(): void
    {
        $boat = $this->boat();

        $this->postJson('/api/v1/damages', [
            'resourceId' => $boat->id,
            'description' => 'diera',
            'severity' => 'MINOR',
        ])->assertCreated();

        $this->assertNull(Damage::query()->latest('createdAt')->first()->reportedByName);
    }

    /* ──────────────  Viditeľnosť mien  ────────────── */

    public function test_members_see_both_names(): void
    {
        $d = $this->damage($this->boat(), [
            'assigneeName' => 'Jozef Servis', 'reportedByName' => 'Anna Členka',
        ]);
        $this->actingAsMember();

        $this->getJson("/api/v1/damages/{$d->id}")
            ->assertOk()
            ->assertJsonPath('assigneeName', 'Jozef Servis')
            ->assertJsonPath('reportedByName', 'Anna Členka');
    }

    public function test_anonymous_visitors_see_the_damage_but_not_the_names(): void
    {
        $d = $this->damage($this->boat(), [
            'assigneeName' => 'Jozef Servis', 'reportedByName' => 'Anna Členka',
        ]);

        $this->getJson("/api/v1/damages/{$d->id}")
            ->assertOk()
            ->assertJsonPath('description', 'prasklina')
            ->assertJsonPath('assigneeName', null)
            ->assertJsonPath('reportedByName', null);
    }

    public function test_pending_accounts_do_not_see_the_names(): void
    {
        $d = $this->damage($this->boat(), [
            'assigneeName' => 'Jozef Servis', 'reportedByName' => 'Anna Členka',
        ]);
        $this->actingAsPending();

        $this->getJson("/api/v1/damages/{$d->id}")
            ->assertOk()
            ->assertJsonPath('assigneeName', null)
            ->assertJsonPath('reportedByName', null);
    }

    public function test_the_names_are_hidden_in_the_list_too(): void
    {
        $this->damage($this->boat(), [
            'assigneeName' => 'Jozef Servis', 'reportedByName' => 'Anna Členka',
        ]);

        $body = $this->getJson('/api/v1/damages')->assertOk()->getContent();

        $this->assertStringNotContainsString('Jozef Servis', $body);
        $this->assertStringNotContainsString('Anna Členka', $body);
    }
}
