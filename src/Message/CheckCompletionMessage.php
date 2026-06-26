<?php

namespace App\Message;

use Symfony\Component\Uid\Uuid;

class CheckCompletionMessage
{
    public function __construct(
        private readonly Uuid $bookingId
    ) {}

    public function getBookingId(): Uuid {
        return $this->bookingId;
    }
}
