<?php

namespace App\DTO;

use App\Enum\BookingStatus;
use App\Enum\ResourceType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use App\Service\BookingCalendarService;

class ResourceListFilterInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Start date is required')]
        #[Assert\DateTime(format: \DateTimeInterface::ATOM, message: 'Start date must be in ATOM format (ISO 8601)')]
        public string $startDate,

        #[Assert\DateTime(format: \DateTimeInterface::ATOM, message: 'End date must be in ATOM format (ISO 8601)')]
        public ?string $endDate = null,

        #[Assert\GreaterThanOrEqual(30, message: 'Duration must be at least 30 minutes')]
        public ?int $duration = null,

        public readonly ?ResourceType $type = null,

        public readonly ?bool $active = null,

        public readonly ?BookingStatus $status = null,

        #[Assert\Uuid]
        public readonly ?string $userId = null,

        #[Assert\GreaterThanOrEqual(1, message: 'Page number must be at least 1')]
        public readonly ?int $page = null,

        #[Assert\GreaterThanOrEqual(1, message: 'Limit must be at least 1')]
        public readonly ?int $limit = null,
    ) {}

    #[Assert\IsFalse(message: 'Start date must be in the future')]
    public function isStartDateInPast(): bool
    {
        try {
            $calendar = new \App\Service\BookingCalendarService();
            $start = $calendar->normalizeStartDate(new \DateTimeImmutable($this->startDate));
            return $start <= new \DateTimeImmutable('now');
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Unified sequential validation of booking parameters depending on the combination of input data
     */
    #[Assert\Callback]
    public function validateBookingParameters(ExecutionContextInterface $context): void
    {
        try {
            $calendar = new \App\Service\BookingCalendarService();

            $rawStart = new \DateTimeImmutable($this->startDate);
            $rawEnd = !empty($this->endDate)
                ? new \DateTimeImmutable($this->endDate)
                : $rawStart->setTime(BookingCalendarService::WORK_END_HOUR, 0, 0);

            // 1. THE FIRST CHECK: Checking the chronological order of the original dates
            if ($rawEnd <= $rawStart) {
                $context->buildViolation('The end date must be later than the start date.')
                    ->atPath('endDate')
                    ->addViolation();
                return;
            }

            // 2. THE SECOND CHECK: If the entire interval is outside of working hours (nights/weekends)
            $totalWorkingMinutes = $calendar->calculateWorkingMinutesBetween($rawStart, $rawEnd);
            if ($totalWorkingMinutes === 0) {
                $context->buildViolation('The requested interval is entirely outside of working hours (nights or weekends).')
                    ->atPath('startDate')
                    ->addViolation();
                return;
            }

            // 3. Normalizing the start to perform subsequent checks
            $start = $calendar->normalizeStartDate($rawStart);

            // 4. Checking the 30-minute limit on the first day and SHIFTING the START
            $checkResult = $calendar->firstDayTimeLimitCheckAndShift($start, $this->endDate, $this->duration, $totalWorkingMinutes);
            $start = $checkResult['start'];
            $durationToCheck = $checkResult['durationToCheck'];
            $isFirstDayShift = $checkResult['isFirstDayShift'];

            // Evaluating the end relative to the normalized start (+ shift)
            $end = !empty($this->endDate)
                ? new \DateTimeImmutable($this->endDate)
                : $start->setTime(BookingCalendarService::WORK_END_HOUR, 0, 0);

            // 5. (AFTER THE SHIFT): Did the start overtake the end after the first day shift?
            if ($isFirstDayShift && $start >= $end) {
                $context->buildViolation('After shifting the start time (to the beginning of the working day - 08:00) due to insufficient time on the first day (<30 mins), the start time moved past or equal to the end time.')
                    ->atPath('startDate')
                    ->addViolation();
                return;
            }

            // 6. Checking the minimum interval between dates after normalization and shift (at least 30 minutes)
            $diffInMinutes = ($end->getTimestamp() - $start->getTimestamp()) / 60;
            if ($diffInMinutes < 30) {
                $shiftViolationMessage = $isFirstDayShift ? ' This rule was violated after the shifting the start time (to the beginning of the working day - 08:00) due to lack of time on the first day (<30 minutes).' : '';
                $context->buildViolation('The interval between start date and end date must be at least 30 minutes.'.$shiftViolationMessage)
                    ->atPath($isFirstDayShift ? 'startDate' : 'endDate')
                    ->addViolation();
                return;
            }

            // 7. Calculating working hours in a normalized interval
            $workingMinutes = $calendar->calculateWorkingMinutesBetween($start, $end);

            if ($durationToCheck !== null) {
                if ($workingMinutes < $durationToCheck) {
                    $shiftViolationMessage = $isFirstDayShift ? 'The start was shifted (to the beginning of the working day - 08:00) due to lack of time on the first day (<30 minutes). ' : '';
                    $context->buildViolation(sprintf(
                        $shiftViolationMessage.'The clear working time (%d mins) between start (%s) and end (%s) date is less than requested duration (%d mins).',
                        $workingMinutes,
                        $start->format('Y-m-d H:i:s'),
                        $end->format('Y-m-d H:i:s'),
                        $durationToCheck
                    ))
                        ->atPath('duration')
                        ->addViolation();
                    return;
                }
            }
        } catch (\Exception) {
            // The standard #[Assert\DateTime] will handle parsing exceptions.
        }
    }
}
