<?php

namespace App\Validator;

use App\Dto\BookingInput;
use App\Service\BookingCalendarService;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class ValidBookingDatesValidator extends ConstraintValidator
{
    public function __construct(
        private readonly int $bookingDurationMin,
        private readonly int $bookingFirstDayTimeMin,
    ) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        /** @var BookingInput $value */
        /** @var ValidBookingDates $constraint */

        // Validating the duration field itself with a dynamic value
        if ($value->duration !== null && $value->duration < $this->bookingDurationMin) {
            $this->context->buildViolation($constraint->durationMinMessage)
                ->setParameter('{{ limit }}', (string)$this->bookingDurationMin)
                ->atPath('duration')
                ->addViolation();
            return;
        }

        try {
            $calendar = new BookingCalendarService();

            // 1. Initial time points from the user
            $rawStart = new \DateTimeImmutable($value->startedAt);
            $rawEnd = !empty($value->endDate)
                ? new \DateTimeImmutable($value->endDate)
                : $rawStart->setTime(BookingCalendarService::WORK_END_HOUR, 0, 0);

            // 2. Chronological order of the source dates
            if ($rawEnd <= $rawStart) {
                $this->context->buildViolation($constraint->startEndDateOrderMessage)
                    ->atPath('endDate')
                    ->addViolation();
                return;
            }

            // Normalization of the starting point based on working hours
            $start = $calendar->normalizeStartDate($rawStart);

            // 3. Checking for the past time (performed relative to the normalized work start)
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($start <= $now) {
                $this->context->buildViolation($constraint->startInPastMessage)
                    ->atPath('startedAt')
                    ->addViolation();
                return;
            }

            // 4. Fully falling into the off-duty interval (night/weekend)
            $totalWorkingMinutes = $calendar->calculateWorkingMinutesBetween($rawStart, $rawEnd);
            if ($totalWorkingMinutes === 0) {
                $this->context->buildViolation($constraint->outsideIntervalMessage)
                    ->atPath('startedAt')
                    ->addViolation();
                return;
            }

            // 5. Checking the $bookingFirstDayTimeMin minute limit on the first day and SHIFTING the START
            $checkResult = $calendar->firstDayTimeLimitCheckAndShift(
                $start,
                $value->endDate,
                $value->duration,
                $totalWorkingMinutes,
                $this->bookingFirstDayTimeMin
            );
            $start = $checkResult['start'];
            $durationToCheck = $checkResult['durationToCheck'];
            $isFirstDayShift = $checkResult['isFirstDayShift'];

            // Evaluating the end relative to the normalized start (+ shift)
            $end = !empty($value->endDate)
                ? new \DateTimeImmutable($value->endDate)
                : $start->setTime(BookingCalendarService::WORK_END_HOUR, 0, 0);

            // 6. (AFTER THE SHIFT): Did the start overtake the end after the first day shift?
            if ($isFirstDayShift && $start >= $end) {
                $this->context->buildViolation(sprintf($constraint->afterShiftStartEndMessage, $this->bookingFirstDayTimeMin))
                    ->atPath('startedAt')
                    ->addViolation();
                return;
            }

            // 7. Checking the minimum interval between dates after normalization and shift (at least $bookingFirstDayTimeMin minutes)
            $diffInMinutes = ($end->getTimestamp() - $start->getTimestamp()) / 60;
            if ($diffInMinutes < $this->bookingDurationMin) {
                $shiftStartEndViolationMessage = $isFirstDayShift
                    ? sprintf($constraint->shiftStartEndViolationMessage, $this->bookingFirstDayTimeMin)
                    : '';
                $violationMessage = sprintf($constraint->minStartEndIntervalMessage, $this->bookingDurationMin) . $shiftStartEndViolationMessage;
                $this->context->buildViolation($violationMessage)
                    ->atPath('endDate')
                    ->addViolation();
                return;
            }

            // 8. Checking if the working minutes match the transmitted duration
            if ($durationToCheck !== null) {
                $workingMinutes = $calendar->calculateWorkingMinutesBetween($start, $end);
                if ($workingMinutes < $durationToCheck) {
                    $shiftWorkMinutesViolationMessage = $isFirstDayShift
                        ? sprintf($constraint->shiftWorkMinutesViolationMessage, $this->bookingFirstDayTimeMin)
                        : '';
                    $violationMessage = $shiftWorkMinutesViolationMessage . $constraint->workMinutesViolationMessage;
                    $this->context->buildViolation(
                        sprintf(
                            $violationMessage,
                            $workingMinutes,
                            $start->format('Y-m-d H:i:s'),
                            $end->format('Y-m-d H:i:s'),
                            $durationToCheck
                        )
                    )
                        ->atPath('duration')
                        ->addViolation();
                }
            }
        } catch (\Exception) {
            // The standard #[Assert\DateTime] will handle parsing exceptions.
        }
    }
}
