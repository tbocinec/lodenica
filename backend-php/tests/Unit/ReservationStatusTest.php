<?php

namespace Tests\Unit;

use App\Domain\Enums\ReservationStatus;
use PHPUnit\Framework\TestCase;

class ReservationStatusTest extends TestCase
{
    public function test_confirmed_and_pending_approval_block_the_slot(): void
    {
        $this->assertTrue(ReservationStatus::CONFIRMED->blocksSlot());
        $this->assertTrue(ReservationStatus::PENDING_APPROVAL->blocksSlot());
    }

    public function test_cancelled_and_rejected_free_the_slot(): void
    {
        $this->assertFalse(ReservationStatus::CANCELLED->blocksSlot());
        $this->assertFalse(ReservationStatus::REJECTED->blocksSlot());
    }

    public function test_blocking_values_lists_exactly_the_blocking_statuses(): void
    {
        $this->assertSame(['CONFIRMED', 'PENDING_APPROVAL'], ReservationStatus::blockingValues());
        $this->assertCount(2, ReservationStatus::blocking());
    }
}
