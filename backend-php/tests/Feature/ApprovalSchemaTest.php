<?php

namespace Tests\Feature;

use App\Domain\Enums\ResourceType;
use App\Domain\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The additive approval-workflow schema (spec §3–4) exists and the models read it. */
class ApprovalSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_and_pivot_table_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('resources', 'requiresApproval'));
        $this->assertTrue(Schema::hasTable('resource_approvers'));
        $this->assertTrue(Schema::hasColumns('reservations', ['decidedById', 'decidedAt', 'decisionNote']));
        $this->assertTrue(Schema::hasColumn('users', 'notificationPrefs'));
    }

    public function test_a_resource_does_not_require_approval_by_default(): void
    {
        $r = Resource::create(['identifier' => 'K-9', 'type' => ResourceType::WW_KAYAK, 'name' => 'Nine']);

        $this->assertFalse($r->refresh()->requiresApproval);
        $this->assertSame('K-9 – Nine', $r->label());
    }

    public function test_approvers_relation_round_trips(): void
    {
        $r = Resource::create(['identifier' => 'S-1', 'type' => ResourceType::BOATHOUSE_SPACE, 'name' => 'Klubovňa', 'requiresApproval' => true]);
        $u = User::create(['name' => 'Schvaľovateľ', 'email' => 's@example.test', 'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true]);
        $other = User::create(['name' => 'Iný', 'email' => 'i@example.test', 'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true]);

        $r->approvers()->attach($u->id);

        $this->assertTrue($r->isApprover($u));
        $this->assertFalse($r->isApprover($other));
        $this->assertSame([$u->id], $r->approvers()->pluck('users.id')->all());
    }

    public function test_notification_prefs_are_an_array_cast(): void
    {
        $u = User::create(['name' => 'P', 'email' => 'p@example.test', 'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true]);
        $u->notificationPrefs = ['reservation_decided' => false];
        $u->save();

        $this->assertSame(['reservation_decided' => false], $u->refresh()->notificationPrefs);
    }
}
