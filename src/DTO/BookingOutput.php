<?php

namespace App\DTO;

use App\Entity\Booking;
use App\Service\BookingCalendarService;

class BookingOutput
{
    public string $id;
    public string $userId;
    public string $resourceId;
    public string $startedAt;
    public string $endedAt;
    public string $status;
    public int $totalTime;
    public int $totalPrice;
    public string $createdAt;

    public static function getBookingOutput(Booking $booking): self
    {
        $calendar = new BookingCalendarService();

        $output = new self();
        $output->id = $booking->getId();
        $output->userId = $booking->getUser()->getId();
        $output->resourceId = $booking->getResource()->getId();
        $output->startedAt = $booking->getStartedAt()->format(\DateTimeInterface::ATOM);
        $output->endedAt = $booking->getEndedAt()->format(\DateTimeInterface::ATOM);
        $output->status = $booking->getStatus()->value;
        $output->totalTime = $calendar->calculateWorkingMinutesBetween($booking->getStartedAt(), $booking->getEndedAt());
        $output->totalPrice = $booking->getTotalPrice();
        $output->createdAt = $booking->getCreatedAt()->format(\DateTimeInterface::ATOM);

        return $output;
    }

    /**
     * Transforms a raw array from the repository for API output
     */
    public static function bookingsOutputPrepare($bookingsOutput, BookingCalendarService $calendarService): array
    {
        usort($bookingsOutput, function ($a, $b) {
            $startA = $a['startedAt'] ?? '';
            $startB = $b['startedAt'] ?? '';

            return $startB <=> $startA;
        });

        foreach ($bookingsOutput as &$bookingData) {
            $startObj = $bookingData['startedAt'] instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($bookingData['startedAt'])
                : new \DateTimeImmutable($bookingData['startedAt']);

            $endObj = $bookingData['endedAt'] instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($bookingData['endedAt'])
                : new \DateTimeImmutable($bookingData['endedAt']);

            $bookingData['totalTime'] = $calendarService->calculateWorkingMinutesBetween($startObj, $endObj);
        }
        unset($bookingData);

        return $bookingsOutput;
    }
}
