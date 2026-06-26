<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\UserRole;
use App\Mail\MembershipApprovedMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MembershipApprovalMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirming_pending_user_sends_approval_email(): void
    {
        Mail::fake();
        $this->actingAsAdmin();

        $pending = User::create([
            'name' => 'Čakateľ', 'email' => 'pending@example.test',
            'password' => 'password123', 'role' => UserRole::PENDING, 'isActive' => true,
        ]);

        $this->postJson("/api/v1/users/{$pending->id}/confirm", ['memberId' => 'KVS-M1'])
            ->assertOk()
            ->assertJsonPath('role', 'MEMBER');

        Mail::assertSent(MembershipApprovedMail::class, fn ($m) => $m->hasTo('pending@example.test'));
    }

    public function test_confirming_already_member_does_not_resend(): void
    {
        Mail::fake();
        $this->actingAsAdmin();

        $member = User::create([
            'name' => 'Člen', 'email' => 'member2@example.test',
            'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true,
        ]);

        $this->postJson("/api/v1/users/{$member->id}/confirm", ['memberId' => 'KVS-M2'])->assertOk();

        Mail::assertNothingSent();
    }
}
