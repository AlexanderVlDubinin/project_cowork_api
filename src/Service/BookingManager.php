<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Message\CheckBookingTimeoutMessage;
use App\Message\SendEmailNotificationMessage;
use App\Repository\BookingRepository;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
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
        private readonly int                    $bookingTechBreak,
    ) {}

    public function createBooking(
        User $user,
        Resource $resource,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end
    ): Booking {

        // Additional time checks
        $isStartCorrect = $this->isWorkingHours($start);
        if (!$isStartCorrect) {
            throw new \LogicException('The start time is not within working hours. The service is open on weekdays from 8:00 to 20:00.');
        }
        $isEndCorrect = $this->isWorkingHours($end);
        if (!$isEndCorrect) {
            throw new \LogicException('The end time is not within working hours. The service is open on weekdays from 8:00 to 20:00.');
        }

        // LEVEL 1: CONTROL AT THE LOGIC LEVEL (Race Condition)
        $dbSearchStart = $start->modify("-{$this->bookingTechBreak} minutes");
        $dbSearchEnd = $end->modify("+{$this->bookingTechBreak} minutes");
        $hasIntersection = $this->bookingRepository->hasOverlappingBookings(
            $resource,
            $dbSearchStart,
            $dbSearchEnd
        );

        if ($hasIntersection) {
            throw new ConflictHttpException('This time interval is already occupied for the selected resource.');
        }

        // LEVEL 2: DATABASE-LEVEL CONTROL (Race Condition)
        try {
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
        } catch (DriverException $e) {
            // If this error occurs, it means that the no_overlapping_books trigger in PostgreSQL has been triggered.
            // This protected against a parallel race condition request.
            $sqlState = $e->getSQLState(); // For "overlap" error, it is '23P01'
            $errorMessage = $e->getMessage();

            if ($sqlState === '23P01' || str_contains($errorMessage, 'no_overlapping_bookings')) {
                // Turning a Database Error into a Symfony HTTP 409 Conflict
                throw new ConflictHttpException('Unfortunately, this time has just been booked. Try something else.', $e);
            }

            // If this is some other DB error, skip it further
            throw new ConflictHttpException('An error occurred at the database level.', $e);
        }

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
