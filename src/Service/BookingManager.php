<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Message\CheckBookingTimeoutMessage;
use App\Message\SendEmailNotificationMessage;
use App\Repository\BookingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class BookingManager
{
    public function __construct(
        private readonly BookingRepository      $bookingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface    $messageBus,
        private readonly LoggerInterface        $logger,
        private readonly int                    $bookingPaymentDelay,
    ) {}

    public function createBooking(
        User $user,
        Resource $resource,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end
    ): Booking {

        $isStartCorrect = $this->isWorkingHours($start);
        if (!$isStartCorrect) {
            throw new \LogicException('The start time is not within working hours. The service is open on weekdays from 8:00 to 20:00.');
        }
        $isEndCorrect = $this->isWorkingHours($end);
        if (!$isEndCorrect) {
            throw new \LogicException('The end time is not within working hours. The service is open on weekdays from 8:00 to 20:00.');
        }

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

        $bookingId = $booking->getId();

        // Instant notification of booking creation
        try {
            $this->messageBus->dispatch(new SendEmailNotificationMessage($bookingId, BookingStatus::PENDING));
        } catch (ExceptionInterface $e) {
            $this->logger->error('Error in queuing the notification: ' . $e->getMessage(), [
                'booking_id' => $bookingId->toString(),
                'status' => $booking->getStatus()->value
            ]);
        }

        // Payment verification task delayed for <BOOKING_PAYMENT_DELAY> minutes (<BOOKING_PAYMENT_DELAY> * 60 * 1000 ms)
        $delay = $this->bookingPaymentDelay * 60 * 1000;
        try {
            $this->messageBus->dispatch(
                new CheckBookingTimeoutMessage($bookingId),
                [new DelayStamp($delay)]
            );
        } catch (ExceptionInterface $e) {
            $this->logger->critical('CRITICAL ERROR: Booking timeout check could not be scheduled: ' . $e->getMessage(), [
                'booking_id' => $bookingId->toString()
            ]);
            throw new \RuntimeException('Booking confirmation failed due to queue error.');
        }

        return $booking;
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

    private function isWorkingHours(\DateTimeInterface $date): bool
    {
        $dayOfWeek = (int)$date->format('N');
        $currentHour = (int)$date->format('G');

        return $dayOfWeek < 6 && ($currentHour >= 8 && $currentHour < 20);
    }
}
