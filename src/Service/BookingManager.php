<?php

namespace App\Service;

use App\DTO\BookingOutput;
use App\Entity\Booking;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Repository\BookingRepository;
use Doctrine\ORM\EntityManagerInterface;

class BookingManager
{
    public function __construct(
        private readonly BookingRepository      $bookingRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function createBooking(
        User $user,
        Resource $resource,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end
    ): BookingOutput {
        if ($this->bookingRepository->hasOverlappingBookings($resource, $start, $end)) {
            throw new \LogicException('This time interval is already occupied for the selected resource.');
        }

        $booking = new Booking();
        $booking->setUser($user);
        $booking->setResource($resource);
        $booking->setStartedAt($start);
        $booking->setEndedAt($end);
        $booking->setStatus(BookingStatus::PENDING);

        $booking->setTotalPrice($this->calculatePrice($resource, $start, $end));

        $this->entityManager->persist($booking);
        $this->entityManager->flush();

        return BookingOutput::getBookingOutput($booking);
    }

    private function calculatePrice(
        Resource $resource,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end
    ): int
    {
        $durationInSeconds = $end->getTimestamp() - $start->getTimestamp();
        $hours = $durationInSeconds / 3600;

        return ceil($resource->getPricePerHour() * $hours);
    }
}
