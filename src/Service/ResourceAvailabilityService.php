<?php

namespace App\Service;

use App\DTO\ResourceListFilterInput;
use App\Repository\BookingRepository;
use App\Repository\ResourceRepository;
use Symfony\Component\Serializer\SerializerInterface;

readonly class ResourceAvailabilityService
{
    public function __construct(
        private ResourceRepository     $resourceRepository,
        private BookingRepository      $bookingRepository,
        private BookingCalendarService $calendarService,
        private SerializerInterface    $serializer,
        private int                    $bookingTechBreak,
    ) {}

    /**
     * Checks the availability of the transferred resources for a specific period specified in the filters.
     * The availability of resources is checked taking into account shifts in non-working hours.
     * Returns a sorted array of only available resources with their nearest slots.
     *
     * Returns an array [resource_id => [start => DateTimeImmutable, end => DateTimeImmutable]|null]
     */
    public function getAvailableResourcesData(ResourceListFilterInput $filters): array
    {
        if (empty($filters->startDate)) {
            return [];
        }

        // 1. Loading all active resources using basic filters (type)
        $resources = $this->resourceRepository->findListForClientByFilters($filters);
        if (empty($resources)) {
            return [];
        }

        // 2. Calculating the time intervals for the search
        // Applying the normalization rules to the start date
        $rawStart = new \DateTimeImmutable($filters->startDate);
        $start = $this->calendarService->normalizeStartDate($rawStart);

        // Repeating the shift logic, confirmed by the validator
        $workEndToday = $start->setTime(BookingCalendarService::WORK_END_HOUR, 0, 0);
        $availableMinutesToday = ($workEndToday->getTimestamp() - $start->getTimestamp()) / 60;

        $rawEnd = !empty($filters->endDate)
            ? new \DateTimeImmutable($filters->endDate)
            : $rawStart->setTime(BookingCalendarService::WORK_END_HOUR, 0, 0);
        $totalWorkingMinutes = $this->calendarService->calculateWorkingMinutesBetween($rawStart, $rawEnd);

        $duration = $filters->duration ?? (empty($filters->endDate) ? 30 : null);
        $targetMinutes = $duration ?? $totalWorkingMinutes;

        if ($targetMinutes > $availableMinutesToday && $availableMinutesToday < 30) {
            $nextDayStart = $start->modify('tomorrow midnight')->setTime(BookingCalendarService::WORK_START_HOUR, 0, 0);
            $start = $this->calendarService->normalizeStartDate($nextDayStart);
        }

        // Calculating the EndDate
        $end = !empty($filters->endDate)
            ? new \DateTimeImmutable($filters->endDate)
            // If the endDate is not specified -> set 20:00 relative to the normalized start
            : $start->setTime(BookingCalendarService::WORK_END_HOUR, 0, 0);

        $duration = $filters->duration ?? (
        empty($filters->endDate)
            ? 30 // If only startDate is specified -> default duration of 30 minutes
            : null
        );

        if ($start >= $end) {
            return [];
        }

        // 3. Calculating extended borders for a single SQL query (accounting for a technical break)

        // Expanding the left border of the database search back for the time of the technical break
        // (if it is not the start of the working day at 08:00)
        $dbSearchStart = $start->format('H:i') !== '08:00'
            ? $start->modify("-$this->bookingTechBreak minutes")
            : $start;

        // Extending the right border of the database search forward for the time of the technical break
        // (if it is not the end of the working day at 20:00)
        $dbSearchEnd = $end->format('H:i') !== '20:00'
            ? $end->modify("+$this->bookingTechBreak minutes")
            : $end;

        // 4. Batch unloading of bookings (Bulk) for all resources in a single request
        $allBookings = $this->bookingRepository->findBookingsForResourcesAndDate($resources, $dbSearchStart, $dbSearchEnd);

        // Grouping bookings by resource ID in a PHP array
        $bookingsByResource = [];
        foreach ($allBookings as $booking) {
            $bookingsByResource[$booking->getResource()->getId()->toString()][] = $booking;
        }

        // 5. Filtering and mapping in one pass
        $availableResourcesData = [];
        foreach ($resources as $resource) {
            $resourceId = $resource->getId()->toString();
            // Receiving existing bookings for the resource
            $existingBookings = $bookingsByResource[$resourceId] ?? [];

            // Looking for a free window for a specific resource
            if ($duration !== null) {
                // Fixed-duration scenarios (including end-to-end multi-day search)
                $slot = $this->findSlotWithDuration($existingBookings, $start, $end, $duration);
            } else {
                // Scenario of a strictly fixed interval from and to (without duration)
                $slot = $this->checkExactIntervalAvailable($existingBookings, $start, $end);
            }

            // If there is no free slot for the selected time, ignore the resource.
            if ($slot === null) {
                continue;
            }

            // Serialize the entity and mix the found slot.
            $resourceArray = $this->serializer->normalize($resource, null, ['groups' => 'resource:read']);

            $resourceArray['nearest_slot'] = [
                'start' => $slot['start']->format(\DateTimeInterface::ATOM),
                'end' => $slot['end']->format(\DateTimeInterface::ATOM),
            ];

            $calendar = new \App\Service\BookingCalendarService();
            $total_time = $calendar->calculateWorkingMinutesBetween($slot['start'], $slot['end']);
            $resourceArray['total_time'] = $total_time;

            $durationInSeconds = $total_time * 60;
            $hours = $durationInSeconds / 3600;
            $total_price = ceil($resource->getPricePerHour() * $hours);
            $resourceArray['total_price'] = $total_price;

            $availableResourcesData[] = $resourceArray;
        }

        // 6. Sorting: the resources that are released earlier go first.
        usort($availableResourcesData, function (array $a, array $b) {
            return $a['nearest_slot']['start'] <=> $b['nearest_slot']['start'];
        });

        return $availableResourcesData;
    }

    /**
     * Search for a free end-to-end time interval containing $neededDuration of working minutes
     */
    private function findSlotWithDuration(array $bookings, \DateTimeImmutable $start, \DateTimeImmutable $end, int $neededDuration): ?array
    {
        $currentPointer = $start;

        while ($currentPointer < $end) {
            // Skipping weekends
            if ((int)$currentPointer->format('N') >= 6) {
                $currentPointer = $currentPointer->modify('tomorrow midnight')->setTime(BookingCalendarService::WORK_START_HOUR, 0, 0);
                continue;
            }

            // Adjustment to the working hours of the current day
            $workStart = $currentPointer->setTime(BookingCalendarService::WORK_START_HOUR, 0, 0);
            $workEnd = $currentPointer->setTime(BookingCalendarService::WORK_END_HOUR, 0, 0);

            if ($currentPointer >= $workEnd) {
                $currentPointer = $currentPointer->modify('tomorrow midnight')->setTime(BookingCalendarService::WORK_START_HOUR, 0, 0);
                continue;
            }

            if ($currentPointer < $workStart) {
                $currentPointer = $workStart;
            }

            // Checking the minimum remaining time on the first day for multi-day bookings
            $minutesLeftFirstDay = ($workEnd->getTimestamp() - $currentPointer->getTimestamp()) / 60;

            if ($neededDuration > $minutesLeftFirstDay && $minutesLeftFirstDay < 30) {
                // If there are less than 30 minutes left before the end of the day, and the booking is longer,
                // postpone the start to tomorrow.
                $currentPointer = $currentPointer->modify('tomorrow midnight')->setTime(BookingCalendarService::WORK_START_HOUR, 0, 0);
                continue;
            }

            // Calculation of the potential end of the segment
            // (with automatic end-to-end transfer through non-working hours/days)
            $slotEnd = $this->calendarService->addWorkingMinutes($currentPointer, $neededDuration);

            // If the required interval is beyond the global search limit (end)
            if ($slotEnd > $end) {
                return null;
            }

            // Checking for intersections with existing bookings
            $hasConflict = false;
            foreach ($bookings as $booking) {
                $bookingStart = $booking->getStartedAt();
                $bookingEnd = $booking->getEndedAt();

                // Correct calculation of the actual resource release time, taking into account technical breaks
                if ($bookingEnd->format('H:i') !== '20:00') {
                    $bEnd = $bookingEnd->modify("+$this->bookingTechBreak minutes");
                } else {
                    $bEnd = $bookingEnd;
                }

                // 1. Checking the standard intersection (our start hit the body of someone else's booking or its technical break)
                if ($currentPointer < $bEnd && $slotEnd > $bookingStart) {
                    $currentPointer = $bEnd;
                    $hasConflict = true;
                    break;
                }

                // 2. Checking the imposition of a TECHNICAL BREAK for a new potential slot at the start of the next booking
                if ($slotEnd <= $bookingStart) {
                    $slotNeedTechBreak = $slotEnd->format('H:i') !== '20:00';
                    $slotEndWithBreak = $slotNeedTechBreak
                        ? $slotEnd->modify("+$this->bookingTechBreak minutes")
                        : $slotEnd;

                    if ($slotEndWithBreak > $bookingStart) {
                        $currentPointer = $bEnd;
                        $hasConflict = true;
                        break;
                    }
                }
            }

            if (!$hasConflict) {
                // An empty through window has been found successfully
                return [
                    'start' => $currentPointer,
                    'end' => $slotEnd
                ];
            }
        }

        return null;
    }

    /**
     * Checking the availability of a fixed interval from and to (without the duration parameter)
     */
    private function checkExactIntervalAvailable(array $bookings, \DateTimeImmutable $start, \DateTimeImmutable $end): ?array
    {
        foreach ($bookings as $booking) {
            $bStart = $booking->getStartedAt();
            $bEnd = $booking->getEndedAt();

            if ($bEnd->format('H:i') !== '20:00') {
                $bEnd = $bEnd->modify("+$this->bookingTechBreak minutes");
            }

            if ($start < $bEnd && $end > $bStart) {
                return null; // The interval is fully or partially occupied
            }
        }

        return [
            'start' => $start,
            'end' => $end
        ];
    }
}
