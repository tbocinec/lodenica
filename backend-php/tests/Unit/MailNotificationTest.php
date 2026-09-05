<?php

namespace Tests\Unit;

use App\Domain\Enums\MailNotification;
use PHPUnit\Framework\TestCase;

class MailNotificationTest extends TestCase
{
    public function test_only_the_two_reservation_notifications_are_user_configurable(): void
    {
        $configurable = array_map(
            fn (MailNotification $t) => $t->value,
            array_values(array_filter(MailNotification::cases(), fn (MailNotification $t) => $t->isUserConfigurable())),
        );

        $this->assertSame(['reservation_approval_requested', 'reservation_decided'], $configurable);
    }

    public function test_every_case_has_copy_and_is_not_critical_unless_it_unlocks_an_account(): void
    {
        foreach (MailNotification::cases() as $type) {
            $this->assertNotSame('', $type->label(), $type->value);
            $this->assertNotSame('', $type->description(), $type->value);
            $this->assertNotSame('', $type->consequence(), $type->value);
        }
        $this->assertFalse(MailNotification::RESERVATION_APPROVAL_REQUESTED->isCritical());
        $this->assertFalse(MailNotification::RESERVATION_DECIDED->isCritical());
    }
}
