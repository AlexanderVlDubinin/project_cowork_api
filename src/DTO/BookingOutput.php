<?php

namespace App\DTO;

use App\Entity\Booking;

class BookingOutput
{
    public string $id;
    public string $userId;
    public string $resourceId;
    public string $startedAt;
    public string $endedAt;
    public string $status;
    public int $totalPrice;
    public string $createdAt;

    public static function getBookingOutput(Booking $booking): self
    {
        $output = new self();
        $output->id = $booking->getId();
        $output->userId = $booking->getUser()->getId();
        $output->resourceId = $booking->getResource()->getId();
        $output->startedAt = $booking->getStartedAt()->format(\DateTimeInterface::ATOM);
        $output->endedAt = $booking->getEndedAt()->format(\DateTimeInterface::ATOM);
        $output->status = $booking->getStatus()->value;
        $output->totalPrice = $booking->getTotalPrice();
        $output->createdAt = $booking->getCreatedAt()->format(\DateTimeInterface::ATOM);

        return $output;
    }
}
