<?php

namespace App\Service;

final class BookingCalendarService
{
    public const WORK_START_HOUR = 8;
    public const WORK_END_HOUR = 20;

    /**
     * Setting the date to the nearest working time (8:00 - 20:00, weekdays only)
     */
    public function normalizeStartDate(\DateTimeImmutable $start): \DateTimeImmutable
    {
        $hour = (int)$start->format('H');

        // 1. If the time is from 20:00 to 24:00, the startDate is moved to 08:00 the next day.
        if ($hour >= self::WORK_END_HOUR) {
            $start = $start->modify('tomorrow')->setTime(self::WORK_START_HOUR, 0, 0);
        }
        // 2. If the time is from 00:00 to 08:00, the startDate is moved to 08:00 of the current day.
        elseif ($hour < self::WORK_START_HOUR) {
            $start = $start->setTime(self::WORK_START_HOUR, 0, 0);
        }

        // 3. If the resulting date falls on a weekend (Sat or Sun), the startDate is moved to next Monday 08:00
        while ((int)$start->format('N') >= 6) {
            $start = $start->modify('tomorrow')->setTime(self::WORK_START_HOUR, 0, 0);
        }

        return $start;
    }

    /**
     * Calculation of net working minutes between two dates
     */
    public function calculateWorkingMinutesBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        $totalMinutes = 0;
        $current = $start;

        // Going through the days in the cycle
        while ($current < $end) {
            $dayOfWeek = (int)$current->format('N');

            // Skip the weekends
            if ($dayOfWeek >= 6) {
                $current = $current->modify('tomorrow midnight');
                continue;
            }

            // Setting the start and end of the working day
            $workStart = $current->setTime(self::WORK_START_HOUR, 0, 0);
            $workEnd = $current->setTime(self::WORK_END_HOUR, 0, 0);

            // Determining the beginning and end of the day for this iteration
            $segmentStart = max($current, $workStart);
            $segmentEnd = $end < $workEnd ? $end : $workEnd;

            // Adding the difference in minutes to the total number of minutes
            if ($segmentStart < $segmentEnd) {
                $totalMinutes += ($segmentEnd->getTimestamp() - $segmentStart->getTimestamp()) / 60;
            }

            // The offset of the pointer to midnight for this iteration
            $current = $current->modify('tomorrow midnight');
        }

        return (int)$totalMinutes;
    }
}
