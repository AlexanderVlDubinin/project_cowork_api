<?php

namespace App\Message;

use App\Enum\BookingStatus;
use Symfony\Component\Uid\Uuid;

class SendEmailNotificationMessage
{
    public function __construct(
        private readonly Uuid          $bookingId,
        private readonly BookingStatus $type
    ) {}

    public function getBookingId(): Uuid {
        return $this->bookingId;
    }

    public function getType(): BookingStatus {
        return $this->type;
    }
}
