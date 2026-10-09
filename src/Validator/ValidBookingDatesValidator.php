<?php

namespace App\Validator;

use App\Dto\BookingInput;
use App\Service\BookingCalendarService;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class ValidBookingDatesValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        /** @var BookingInput $value */
        /** @var ValidBookingDates $constraint */

        try {
            $calendar = new BookingCalendarService();

            // 1. Initial time points from the user
            $rawStart = new \DateTimeImmutable($value->startedAt);
            $rawEnd = !empty($value->endDate)
                ? new \DateTimeImmutable($value->endDate)
                : $rawStart->setTime(BookingCalendarService::WORK_END_HOUR, 0, 0);

            // 2. Chronological order of the source dates
            if ($rawEnd <= $rawStart) {
                $this->context->buildViolation($constraint->message)
                    ->atPath('endDate')
                    ->addViolation();
                return;
            }

            // 3. Normalization of the starting point based on working hours
            $start = $calendar->normalizeStartDate($rawStart);

            // 4. Checking for the past time (performed relative to the normalized work start)
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($start <= $now) {
                $this->context->buildViolation($constraint->pastMessage)
                    ->atPath('startedAt')
                    ->addViolation();
                return;
            }

            // 5. Fully falling into the off-duty interval (night/weekend)
            $totalWorkingMinutes = $calendar->calculateWorkingMinutesBetween($rawStart, $rawEnd);
            if ($totalWorkingMinutes === 0) {
                $this->context->buildViolation($constraint->outsideIntervalMessage)
                    ->atPath('startedAt')
                    ->addViolation();
                return;
            }

            // 6. Checking the 30-minute limit on the first day and SHIFTING the START
            $checkResult = $calendar->firstDayTimeLimitCheckAndShift($start, $value->endDate, $value->duration, $totalWorkingMinutes);
            $start = $checkResult['start'];
            $durationToCheck = $checkResult['durationToCheck'];
            $isFirstDayShift = $checkResult['isFirstDayShift'];

            // Evaluating the end relative to the normalized start (+ shift)
            $end = !empty($value->endDate)
                ? new \DateTimeImmutable($value->endDate)
                : $start->setTime(BookingCalendarService::WORK_END_HOUR, 0, 0);

            // 7. (AFTER THE SHIFT): Did the start overtake the end after the first day shift?
            if ($isFirstDayShift && $start >= $end) {
                $this->context->buildViolation($constraint->afterShiftStartEndMessage)
                    ->atPath('startedAt')
                    ->addViolation();
                return;
            }

            // 8. Checking the minimum interval between dates after normalization and shift (at least 30 minutes)
            $diffInMinutes = ($end->getTimestamp() - $start->getTimestamp()) / 60;
            if ($diffInMinutes < 30) {
                $shiftStartEndViolationMessage = $isFirstDayShift ? $constraint->shiftStartEndViolationMessage : '';
                $this->context->buildViolation($constraint->minStartEndIntervalMessage . $shiftStartEndViolationMessage)
                    ->atPath('endDate')
                    ->addViolation();
                return;
            }

            // 9. Checking if the working minutes match the transmitted duration
            if ($durationToCheck !== null) {
                $workingMinutes = $calendar->calculateWorkingMinutesBetween($start, $end);
                if ($workingMinutes < $durationToCheck) {
                    $shiftWorkMinutesViolationMessage = $isFirstDayShift ? $constraint->shiftWorkMinutesViolationMessage : '';
                    $this->context->buildViolation(sprintf(
                        $shiftWorkMinutesViolationMessage . $constraint->workMinutesViolationMessage,
                        $workingMinutes,
                        $start->format('Y-m-d H:i:s'),
                        $end->format('Y-m-d H:i:s'),
                        $durationToCheck
                    ))
                        ->atPath('duration')
                        ->addViolation();
                }
            }
        } catch (\Exception) {
            // The standard #[Assert\DateTime] will handle parsing exceptions.
        }
    }
}
