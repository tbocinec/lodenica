<?php

namespace Tests\Feature;

use App\Domain\Enums\ReservationStatus;
use App\Domain\Enums\ResourceType;
use App\Exceptions\InactiveResourceException;
use App\Exceptions\InvalidDateRangeException;
use App\Exceptions\NotFoundDomainException;
use App\Exceptions\ReservationOverlapException;
use App\Models\Resource;
use App\Services\ReservationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class ReservationsServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReservationsService $service;
    private Resource $activeKayak;
    private Resource $inactiveKayak;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReservationsService::class);

        $this->activeKayak = Resource::create([
            'identifier' => 'K-1',
            'type' => ResourceType::WW_KAYAK,
            'name' => 'Kayak 1',
        ]);
        $this->inactiveKayak = Resource::create([
            'identifier' => 'K-2',
            'type' => ResourceType::WW_KAYAK,
            'name' => 'Kayak 2',
            'isActive' => false,
        ]);
    }

    public function test_creates_a_reservation_when_no_conflict(): void
    {
        $r = $this->service->create([
            'resourceId' => $this->activeKayak->id,
            'customerName' => 'Ján',
            'startsAt' => '2026-05-10T09:00:00Z',
            'endsAt' => '2026-05-10T12:00:00Z',
        ]);

        $this->assertSame(ReservationStatus::CONFIRMED, $r->status);
        $this->assertSame(3.0, $r->range()->durationHours());
    }

    public function test_rejects_inactive_resource(): void
    {
        $this->expectException(InactiveResourceException::class);
        $this->service->create([
            'resourceId' => $this->inactiveKayak->id,
            'customerName' => 'Ján',
            'startsAt' => '2026-05-10T09:00:00Z',
            'endsAt' => '2026-05-10T10:00:00Z',
        ]);
    }

    public function test_rejects_missing_resource(): void
    {
        $this->expectException(NotFoundDomainException::class);
        $this->service->create([
            'resourceId' => Uuid::uuid4()->toString(),
            'customerName' => 'Ján',
            'startsAt' => '2026-05-10T09:00:00Z',
            'endsAt' => '2026-05-10T10:00:00Z',
        ]);
    }

    public function test_rejects_end_before_start(): void
    {
        $this->expectException(InvalidDateRangeException::class);
        $this->service->create([
            'resourceId' => $this->activeKayak->id,
            'customerName' => 'Ján',
            'startsAt' => '2026-05-10T12:00:00Z',
            'endsAt' => '2026-05-10T09:00:00Z',
        ]);
    }

    public function test_rejects_zero_length(): void
    {
        $this->expectException(InvalidDateRangeException::class);
        $this->service->create([
            'resourceId' => $this->activeKayak->id,
            'customerName' => 'Ján',
            'startsAt' => '2026-05-10T12:00:00Z',
            'endsAt' => '2026-05-10T12:00:00Z',
        ]);
    }

    public static function overlappingTimes(): array
    {
        return [
            'exact match' => ['2026-05-10T09:00:00Z', '2026-05-10T12:00:00Z'],
            'inside existing' => ['2026-05-10T10:00:00Z', '2026-05-10T11:00:00Z'],
            'overlapping start' => ['2026-05-10T08:00:00Z', '2026-05-10T10:00:00Z'],
            'overlapping end' => ['2026-05-10T11:00:00Z', '2026-05-10T13:00:00Z'],
            'fully containing' => ['2026-05-10T00:00:00Z', '2026-05-10T23:59:59Z'],
        ];
    }

    #[DataProvider('overlappingTimes')]
    public function test_rejects_overlapping_reservation(string $start, string $end): void
    {
        $this->seedExistingBooking();

        $this->expectException(ReservationOverlapException::class);
        $this->service->create([
            'resourceId' => $this->activeKayak->id,
            'customerName' => 'New',
            'startsAt' => $start,
            'endsAt' => $end,
        ]);
    }

    public static function nonOverlappingTimes(): array
    {
        return [
            'back-to-back before (handover)' => ['2026-05-10T06:00:00Z', '2026-05-10T09:00:00Z'],
            'back-to-back after (handover)' => ['2026-05-10T12:00:00Z', '2026-05-10T15:00:00Z'],
            'next day' => ['2026-05-11T09:00:00Z', '2026-05-11T12:00:00Z'],
        ];
    }

    #[DataProvider('nonOverlappingTimes')]
    public function test_accepts_non_overlapping(string $start, string $end): void
    {
        $this->seedExistingBooking();

        $r = $this->service->create([
            'resourceId' => $this->activeKayak->id,
            'customerName' => 'New',
            'startsAt' => $start,
            'endsAt' => $end,
        ]);
        $this->assertNotNull($r->id);
    }

    public function test_allows_other_resources_to_share_time(): void
    {
        $this->seedExistingBooking();
        $other = Resource::create([
            'identifier' => 'K-9',
            'type' => ResourceType::WW_KAYAK,
            'name' => 'Kayak 9',
        ]);

        $r = $this->service->create([
            'resourceId' => $other->id,
            'customerName' => 'New',
            'startsAt' => '2026-05-10T09:00:00Z',
            'endsAt' => '2026-05-10T12:00:00Z',
        ]);
        $this->assertNotNull($r->id);
    }

    public function test_ignores_cancelled_reservations_for_overlap(): void
    {
        $existing = $this->seedExistingBooking();
        $this->service->cancel($existing->id);

        $r = $this->service->create([
            'resourceId' => $this->activeKayak->id,
            'customerName' => 'New',
            'startsAt' => '2026-05-10T09:00:00Z',
            'endsAt' => '2026-05-10T12:00:00Z',
        ]);
        $this->assertNotNull($r->id);
    }

    public function test_update_does_not_consider_itself(): void
    {
        $existing = $this->seedExistingBooking();
        $updated = $this->service->update($existing->id, [
            'startsAt' => '2026-05-10T10:00:00Z',
            'endsAt' => '2026-05-10T13:00:00Z',
        ]);
        $this->assertSame('2026-05-10T13:00:00+00:00', $updated->endsAt->toIso8601String());
    }

    public function test_cancellation_marks_but_does_not_delete(): void
    {
        $created = $this->seedExistingBooking();
        $cancelled = $this->service->cancel($created->id);
        $this->assertSame(ReservationStatus::CANCELLED, $cancelled->status);
    }

    public function test_hard_delete_removes(): void
    {
        $created = $this->seedExistingBooking();
        $this->service->remove($created->id);

        $this->expectException(NotFoundDomainException::class);
        $this->service->findById($created->id);
    }

    public function test_after_delete_the_slot_is_free_again(): void
    {
        $created = $this->seedExistingBooking();
        $this->service->remove($created->id);

        $r = $this->service->create([
            'resourceId' => $this->activeKayak->id,
            'customerName' => 'Second',
            'startsAt' => '2026-05-10T09:00:00Z',
            'endsAt' => '2026-05-10T12:00:00Z',
        ]);
        $this->assertNotNull($r->id);
    }

    private function seedExistingBooking()
    {
        return $this->service->create([
            'resourceId' => $this->activeKayak->id,
            'customerName' => 'Existing',
            'startsAt' => '2026-05-10T09:00:00Z',
            'endsAt' => '2026-05-10T12:00:00Z',
        ]);
    }
}
