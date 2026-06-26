<?php

namespace App\Service;

use App\DTO\ResourceListFilterInput;
use App\Repository\BookingRepository;
use Symfony\Component\Serializer\SerializerInterface;

class ResourceAvailabilityService
{
    private const WORK_START = 8;
    private const WORK_END = 20;

    public function __construct(
        private readonly BookingRepository $bookingRepository,
        private readonly int $bookingTechBreak,
    ) {}

    /**
     * Returns an array [resource_id => [start => DateTimeImmutable, end => DateTimeImmutable]|null]
     */
    public function findNearestIntervals( array $resources, ResourceListFilterInput $filters): array {
        $durationMinutes = $filters->duration ?? 30; // Default duration
        $techBreakInterval = $this->bookingTechBreak;

        $searchFrom =$this->getSearchFrom($filters);
        if (empty($searchFrom)) {
            return [];
        } else {
            $searchFrom = $searchFrom[0];
        }

        // The end time of the working day for the search
        $workDayEnd = $searchFrom->setTime(self::WORK_END, 0, 0);

        $result = [];

        foreach ($resources as $resource) {
            // Getting the booked intervals for that day, sorted by startedAt
            $existingBookings = $this->bookingRepository->findBookingsForDate(
                $resource,
                $searchFrom->modify("+{$techBreakInterval} minutes"),
                $workDayEnd
            );

            $result[(string)$resource->getId()] = $this->calculateFirstFreeSlot(
                $existingBookings,
                $searchFrom,
                $workDayEnd,
                $durationMinutes,
                $techBreakInterval
            );
        }

        return $result;
    }

    private function calculateFirstFreeSlot(
        array $bookings,
        \DateTimeImmutable $currentPointer,
        \DateTimeImmutable $workDayEnd,
        int $durationMinutes,
        int $techBreakInterval
    ): ?array {
        $neededInterval = new \DateInterval("PT{$durationMinutes}M");
        $techBreakInterval = new \DateInterval("PT{$techBreakInterval}M");

        // If there are no bookings at all, the resource is available right now
        if (empty($bookings)) {
            // Checking whether the booking will be completed before the end of the working day (20:00)
            if ($currentPointer->add($neededInterval) <= $workDayEnd) {
                return [
                    'start' => $currentPointer,
                    'end' => $currentPointer->add($neededInterval)
                ];
            }
            return null;
        }

        // If there are bookings, start the gap check cycle
        foreach ($bookings as $booking) {
            $bookingStart = $booking->getStartedAt();
            $bookingEnd = $booking->getEndedAt();

            // Calculating the time when the room is actually vacated after the break
            $earliestAvailableStart = $bookingEnd->add($techBreakInterval);

            // If the search time is inside someone else's booking or inside its break
            // Moving the pointer to the end time of the break
            if ($currentPointer < $earliestAvailableStart && $bookingStart <= $currentPointer) {
                $currentPointer = $earliestAvailableStart;
            }

            // Check the gap before the next booking
            // This booking + break should be enough BEFORE the start of the next booking
            $totalTimeWithNextBreak = $currentPointer
                ->add($neededInterval)
                ->add($techBreakInterval);

            if ($totalTimeWithNextBreak <= $bookingStart) {
                return [
                    'start' => $currentPointer,
                    'end' => $currentPointer->add($neededInterval)
                ];
            }

            // If the current booking overlaps the pointer, move it to a safe start time
            if ($earliestAvailableStart > $currentPointer) {
                $currentPointer = $earliestAvailableStart;
            }
        }

        // Checking at the end of the working day (after all available bookings)
        // A: New booking + a break can be fully accommodated until 20:00
        $totalNeededTime = new \DateInterval("PT" . ($durationMinutes + $this->bookingTechBreak) . "M");

        if ($currentPointer->add($totalNeededTime) <= $workDayEnd) {
            return [
                'start' => $currentPointer,
                'end' => $currentPointer->add($neededInterval)
            ];
        }

        // B: The break may go beyond 20:00, but the booking itself ends at exactly 20:00
        if ($currentPointer->add($neededInterval) <= $workDayEnd) {
            return [
                'start' => $currentPointer,
                'end' => $currentPointer->add($neededInterval)
            ];
        }

        return null;
    }

    private function getSearchFrom($filters): array
    {
        $now = new \DateTimeImmutable();
        if ($filters->startDate === null) {
            $now = $now->setTimezone(new \DateTimeZone('UTC'));
            $currentHour = (int)$now->format('H');
            if ($currentHour >= self::WORK_END) {
                return [];
            }
            if ($currentHour < self::WORK_START) {
                $searchFrom = $now->setTime(self::WORK_START, 0, 0);
            } else {
                $timestamp = $now->getTimestamp();
                $ceilTimestamp = ceil($timestamp / 300) * 300;
                $searchFrom = $now->setTimestamp($ceilTimestamp);
            }
        } else {
            $filterFrom = new \DateTimeImmutable($filters->startDate);
            $filterFrom = $filterFrom->setTimezone(new \DateTimeZone('UTC'));
            $filterHour = (int)$filterFrom->format('H');
            if ($filterHour >= self::WORK_END) {
                return [];
            }
            $now = $now->setTimezone(new \DateTimeZone('UTC'));
            if ($filterHour < self::WORK_START) {
                $searchFrom = $now->setTime(self::WORK_START, 0, 0);
            } else {
                $timestamp = $filterFrom->getTimestamp();
                $ceilTimestamp = ceil($timestamp / 300) * 300;
                $searchFrom = $now->setTimestamp($ceilTimestamp);
            }
        }

        return [$searchFrom];
    }

    public function generateResponseData(
        SerializerInterface $serializer,
        array $resources,
        array $nearestSlots): array
    {
        $responseData = [];
        foreach ($resources as $resource) {
            $resourceArray = $serializer->normalize($resource, null, ['groups' => 'resource:read']);
            $slot = $nearestSlots[(string)$resource->getId()];

            // $resourceArray['nearest_slot'] = null;
            if ($slot) {
                $resourceArray['nearest_slot'] = [
                    'start' => $slot['start']->format(\DateTimeInterface::ATOM),
                    'end' => $slot['end']->format(\DateTimeInterface::ATOM)
                ];

                $responseData[] = $resourceArray;
            }

            // $responseData[] = $resourceArray;
        }

        // sorting by nearest slot start time
        usort($responseData, function ($a, $b) {
            $startA = $a['nearest_slot']['start'] ?? '';
            $startB = $b['nearest_slot']['start'] ?? '';

            return $startA <=> $startB;
        });

        return $responseData;
    }
}
