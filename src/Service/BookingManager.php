<?php

namespace App\Service;

use App\DTO\BookingInput;
use App\Entity\Booking;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Message\CheckBookingTimeoutMessage;
use App\Message\SendEmailNotificationMessage;
use App\Repository\BookingRepository;
use App\Repository\ResourceRepository;
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
        private readonly ResourceRepository     $resourceRepository,
        private readonly BookingRepository      $bookingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface    $messageBus,
        private readonly LoggerInterface        $logger,
        private readonly BookingCalendarService $calendarService,
        private readonly int                    $bookingPaymentDelay,
        private readonly int                    $bookingTechBreak,
        private readonly int                    $bookingFirstDayTimeMin
    ) {}

    public function createBookingFromParams(
        User $user,
        BookingInput $bookingInput,
        \DateTimeImmutable $rawStart
    ): Booking {
        $resourceId = $bookingInput->resourceId;
        $rawEndDate = $bookingInput->endDate;
        $duration = $bookingInput->duration;

        // Checking the resource
        $resource = $this->resourceRepository->find($resourceId);
        if (!$resource || !$resource->isActive()) {
            throw new ConflictHttpException('Resource not found or unavailable for booking.');
        }

        // 1. SECURITY CHECK OF SOURCE DATES
        if (!empty($rawEndDate)) {
            $rawEnd = new \DateTimeImmutable($rawEndDate);
        } else {
            // If the end is not transmitted, temporarily calculate it as 20:00 from the start date.
            $rawEnd = $rawStart->setTime(BookingCalendarService::WORK_END_HOUR, 0, 0);
        }

        if ($rawEnd <= $rawStart) {
            throw new \LogicException('The end date must be later than the start date.');
        }

        // If the entire transmitted interval is outside of business hours (nights or weekends).
        $initialWorkingMinutes = $this->calendarService->calculateWorkingMinutesBetween($rawStart, $rawEnd);
        if ($initialWorkingMinutes === 0) {
            throw new \LogicException('The requested interval is entirely outside of working hours (nights or weekends).');
        }

        // 2. NORMALIZATION OF THE STARTING POINT
        $start = $this->calendarService->normalizeStartDate($rawStart);

        // 3.FIRST DAY RULE (Minimum $bookingFirstDayTimeMin minutes of working time on the first day)
        $checkResult = $this->calendarService->firstDayTimeLimitCheckAndShift(
            $start,
            $rawEndDate,
            $duration,
            $initialWorkingMinutes,
            $this->bookingFirstDayTimeMin
        );
        $start = $checkResult['start'];

        // 4. THE FINAL CALCULATION OF ENDED_AT
        if ($duration !== null) {
            // If duration is passed, calculate the end-to-end by working hours and weekends.
            $end = $this->calendarService->addWorkingMinutes($start, $duration);
        } else {
            // If there is no duration, take the transmitted fixed end (or default 20:00 from the normalized start)
            if (!empty($rawEndDate)) {
                $end = new \DateTimeImmutable($rawEndDate);
            } else {
                $end = $start->setTime(BookingCalendarService::WORK_END_HOUR, 0, 0);
            }
        }

        if ($start >= $end) {
            throw new \LogicException('Calculated start date cannot be greater than or equal to end date.');
        }

        // 5. CHECKING OVERLAYS TAKING INTO ACCOUNT THE TECHNICAL BREAK

        // LEVEL 1: CONTROL AT THE LOGIC LEVEL (Race Condition)
        if ($start->format('H:i') !== '08:00') {
            $dbSearchStart = $start->modify("-{$this->bookingTechBreak} minutes");
        } else {
            $dbSearchStart = $start;
        }

        if ($end->format('H:i') !== '20:00') {
            $dbSearchEnd = $end->modify("+{$this->bookingTechBreak} minutes");
        } else {
            $dbSearchEnd = $end;
        }

        $hasIntersection = $this->bookingRepository->hasOverlappingBookings($resource, $dbSearchStart, $dbSearchEnd);
        if ($hasIntersection) {
            throw new ConflictHttpException('This time interval is already occupied for the selected resource.');
        }

        // 6. SAVING AND COST

        // LEVEL 2: DATABASE-LEVEL CONTROL (Race Condition)
        try {
            $booking = new Booking();
            $booking->setUser($user);
            $booking->setResource($resource);
            $booking->setStartedAt($start);
            $booking->setEndedAt($end);
            $booking->setStatus(BookingStatus::PENDING);

            // Writing down the extended time borders (with a technical break) for the GiST index of the DBMS
            if ($start->format('H:i') !== '08:00') {
                $booking->setDbBlockedFrom($start->modify("-{$this->bookingTechBreak} minutes"));
            } else {
                $booking->setDbBlockedFrom($start);
            }

            if ($end->format('H:i') !== '20:00') {
                $booking->setDbBlockedTo($end->modify("+{$this->bookingTechBreak} minutes"));
            } else {
                $booking->setDbBlockedTo($end);
            }

            $workingMinutes = $this->calendarService->calculateWorkingMinutesBetween($start, $end);
            $hours = $workingMinutes / 60;
            $booking->setTotalPrice((int)ceil($resource->getPricePerHour() * $hours));

            $this->entityManager->persist($booking);
            $this->entityManager->flush();

            $bookingId = $booking->getId();
        } catch (DriverException $e) {
            // If this error occurs, it means that the no_overlapping_books trigger in PostgreSQL has been triggered.
            // This protected against a parallel race condition request.
            $sqlState = $e->getSQLState();
            $errorMessage = $e->getMessage();

            if ($sqlState === '23P01' || str_contains($errorMessage, 'no_overlapping_bookings')) {
                // Turning a Database Error into a Symfony HTTP 409 Conflict
                throw new ConflictHttpException('Unfortunately, this time has just been booked. Try something else.', $e);
            }
            // If this is some other DB error, skip it further
            throw new ConflictHttpException('An error occurred at the database level.', $e);
        }

        // 7. NOTIFICATIONS AND PAYMENT TIMEOUTS

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
            $this->logger->critical('CRITICAL ERROR: Booking timeout check failed: ' . $e->getMessage(), [
                'booking_id' => $bookingId->toString()
            ]);
            throw new \RuntimeException('Booking confirmation failed due to queue error.');
        }

        return $booking;
    }
}
