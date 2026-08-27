<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\DamageSeverity;
use App\Domain\Enums\DamageStatus;
use App\Domain\Enums\ResourceType;
use App\Models\Damage;
use App\Models\DamageComment;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Discussion thread on a damage. Comments carry personal names, so — like
 * the assignee/reporter fields — they are confirmed-members-only, to read
 * as well as to write.
 */
class DamageCommentsApiTest extends TestCase
{
    use RefreshDatabase;

    private function damage(): Damage
    {
        $boat = Resource::create([
            'identifier' => 'K-C-'.bin2hex(random_bytes(3)),
            'type' => ResourceType::WW_KAYAK,
            'name' => 'Komentovaná',
        ]);

        return Damage::create([
            'resourceId' => $boat->id,
            'description' => 'prasklina',
            'severity' => DamageSeverity::MODERATE,
            'status' => DamageStatus::REPORTED,
        ]);
    }

    private function commentBy(Damage $damage, User $author, string $body = 'text'): DamageComment
    {
        return DamageComment::create([
            'damageId' => $damage->id,
            'authorId' => $author->id,
            'authorName' => $author->name,
            'body' => $body,
        ]);
    }

    /* ──────────────  Gating  ────────────── */

    public function test_anonymous_visitors_cannot_read_or_write_comments(): void
    {
        $d = $this->damage();

        $this->getJson("/api/v1/damages/{$d->id}/comments")->assertStatus(401);
        $this->postJson("/api/v1/damages/{$d->id}/comments", ['body' => 'ahoj'])->assertStatus(401);
    }

    public function test_pending_accounts_cannot_read_or_write_comments(): void
    {
        $d = $this->damage();
        $this->actingAsPending();

        $this->getJson("/api/v1/damages/{$d->id}/comments")->assertStatus(403);
        $this->postJson("/api/v1/damages/{$d->id}/comments", ['body' => 'ahoj'])->assertStatus(403);
    }

    /* ──────────────  Písanie  ────────────── */

    public function test_a_member_can_post_a_comment(): void
    {
        $d = $this->damage();
        $this->actingAsMember(['name' => 'Anna Členka']);

        $this->postJson("/api/v1/damages/{$d->id}/comments", ['body' => 'Doniesol som lepidlo.'])
            ->assertCreated()
            ->assertJsonPath('body', 'Doniesol som lepidlo.')
            ->assertJsonPath('authorName', 'Anna Členka');
    }

    public function test_comment_body_is_validated(): void
    {
        $d = $this->damage();
        $this->actingAsMember();

        $this->postJson("/api/v1/damages/{$d->id}/comments", ['body' => ''])->assertStatus(400);
        $this->postJson("/api/v1/damages/{$d->id}/comments", [])->assertStatus(400);
        $this->postJson("/api/v1/damages/{$d->id}/comments", ['body' => str_repeat('a', 2001)])
            ->assertStatus(400);
    }

    public function test_comments_come_back_oldest_first(): void
    {
        $d = $this->damage();
        $author = $this->actingAsMember();
        $this->commentBy($d, $author, 'prvý');
        $this->commentBy($d, $author, 'druhý');
        $this->commentBy($d, $author, 'tretí');

        $bodies = array_column(
            $this->getJson("/api/v1/damages/{$d->id}/comments")->assertOk()->json('items'),
            'body',
        );

        $this->assertSame(['prvý', 'druhý', 'tretí'], $bodies);
    }

    public function test_comments_of_other_damages_do_not_leak_in(): void
    {
        $mine = $this->damage();
        $other = $this->damage();
        $author = $this->actingAsMember();
        $this->commentBy($mine, $author, 'patrí sem');
        $this->commentBy($other, $author, 'patrí inam');

        $items = $this->getJson("/api/v1/damages/{$mine->id}/comments")->assertOk()->json('items');

        $this->assertCount(1, $items);
        $this->assertSame('patrí sem', $items[0]['body']);
    }

    /**
     * The name is snapshotted at write time so a renamed or deleted account
     * doesn't rewrite history — same reasoning as customerName on bookings.
     */
    public function test_the_author_name_survives_the_account_being_deleted(): void
    {
        $d = $this->damage();
        $author = $this->actingAsMember(['name' => 'Odídený Člen']);
        $this->commentBy($d, $author, 'ešte tu bol');

        $author->delete();
        $this->actingAsMember();

        $items = $this->getJson("/api/v1/damages/{$d->id}/comments")->assertOk()->json('items');
        $this->assertSame('Odídený Člen', $items[0]['authorName']);
        $this->assertNull($items[0]['authorId']);
    }

    /* ──────────────  Mazanie  ────────────── */

    public function test_an_author_can_delete_their_own_comment(): void
    {
        $d = $this->damage();
        $author = $this->actingAsMember();
        $c = $this->commentBy($d, $author);

        $this->deleteJson("/api/v1/damages/{$d->id}/comments/{$c->id}")->assertNoContent();
        $this->assertDatabaseMissing('damage_comments', ['id' => $c->id]);
    }

    public function test_another_member_cannot_delete_someone_elses_comment(): void
    {
        $d = $this->damage();
        $author = User::create([
            'name' => 'Autor', 'email' => 'autor@example.test', 'password' => 'password123',
            'role' => \App\Domain\Enums\UserRole::MEMBER, 'isActive' => true,
        ]);
        $c = $this->commentBy($d, $author);

        $this->actingAsMember();
        $this->deleteJson("/api/v1/damages/{$d->id}/comments/{$c->id}")->assertStatus(403);
        $this->assertDatabaseHas('damage_comments', ['id' => $c->id]);
    }

    public function test_an_admin_can_delete_any_comment(): void
    {
        $d = $this->damage();
        $author = User::create([
            'name' => 'Autor', 'email' => 'autor@example.test', 'password' => 'password123',
            'role' => \App\Domain\Enums\UserRole::MEMBER, 'isActive' => true,
        ]);
        $c = $this->commentBy($d, $author);

        $this->actingAsAdmin();
        $this->deleteJson("/api/v1/damages/{$d->id}/comments/{$c->id}")->assertNoContent();
        $this->assertDatabaseMissing('damage_comments', ['id' => $c->id]);
    }

    public function test_deleting_the_damage_takes_its_comments_with_it(): void
    {
        $d = $this->damage();
        $author = $this->actingAsAdmin();
        $c = $this->commentBy($d, $author);

        $this->deleteJson("/api/v1/damages/{$d->id}")->assertNoContent();

        $this->assertDatabaseMissing('damage_comments', ['id' => $c->id]);
    }
}
