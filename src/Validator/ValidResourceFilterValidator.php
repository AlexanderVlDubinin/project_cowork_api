<?php

namespace App\Validator;

use App\DTO\ResourceListFilterInput;
use App\Service\BookingCalendarService;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class ValidResourceFilterValidator extends ConstraintValidator
{
    public function __construct(
        private readonly int $bookingDurationMin,
        private readonly int $bookingFirstDayTimeMin
    ) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        /** @var ResourceListFilterInput $value */
        /** @var ValidResourceFilter $constraint */

        // 0. CHECKING THE DURATION FIELD (instead of the GreaterThanOrEqual attribute)
        if ($value->duration !== null && $value->duration < $this->bookingDurationMin) {
            $this->context->buildViolation($constraint->durationMinMessage)
                ->setParameter('{{ limit }}', (string)$this->bookingDurationMin)
                ->atPath('duration')
                ->addViolation();
            return;
        }

        try {
            $calendar = new BookingCalendarService();

            $rawStart = new \DateTimeImmutable($value->startDate);
            $rawEnd = !empty($value->endDate)
                ? new \DateTimeImmutable($value->endDate)
                : $rawStart->setTime(BookingCalendarService::WORK_END_HOUR, 0, 0);

            // 1. THE FIRST CHECK: Checking the chronological order of the original dates
            if ($rawEnd <= $rawStart) {
                $this->context->buildViolation($constraint->startEndDateOrderMessage)
                    ->atPath('endDate')
                    ->addViolation();
                return;
            }

            // 2. THE SECOND CHECK: If the entire interval is outside of working hours (nights/weekends)
            $totalWorkingMinutes = $calendar->calculateWorkingMinutesBetween($rawStart, $rawEnd);
            if ($totalWorkingMinutes === 0) {
                $this->context->buildViolation($constraint->outsideIntervalMessage)
                    ->atPath('startDate')
                    ->addViolation();
                return;
            }

            // Normalizing the start to perform subsequent checks
            $start = $calendar->normalizeStartDate($rawStart);

            // 3. THE THIRD CHECK: Start date must be in the future
            if ($start <= new \DateTimeImmutable('now')) {
                $this->context->buildViolation($constraint->startInPastMessage)
                    ->atPath('startDate')
                    ->addViolation();
                return;
            }

            // 4. Checking the $bookingFirstDayTimeMin limit on the first day and SHIFTING the START
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

            // 5. (AFTER THE SHIFT): Did the start overtake the end after the first day shift?
            if ($isFirstDayShift && $start >= $end) {
                $violationMessage = sprintf(
                    $constraint->afterShiftStartEndMessage,
                    $this->bookingFirstDayTimeMin
                );
                $this->context->buildViolation($violationMessage)
                    ->atPath('startDate')
                    ->addViolation();
                return;
            }

            // 6. Checking the minimum interval between dates after normalization and shift (at least $bookingDurationMin minutes)
            $diffInMinutes = ($end->getTimestamp() - $start->getTimestamp()) / 60;
            if ($diffInMinutes < $this->bookingDurationMin) {
                $shiftViolationMessage = $isFirstDayShift
                    ? sprintf(
                        $constraint->shiftStartEndViolationMessage,
                        $this->bookingFirstDayTimeMin
                    )
                    : '';
                $this->context->buildViolation(sprintf($constraint->minStartEndIntervalMessage, $this->bookingDurationMin) . $shiftViolationMessage)
                    ->atPath($isFirstDayShift ? 'startDate' : 'endDate')
                    ->addViolation();
                return;
            }

            // 7. Calculating working hours in a normalized interval
            $workingMinutes = $calendar->calculateWorkingMinutesBetween($start, $end);

            if ($durationToCheck !== null) {
                if ($workingMinutes < $durationToCheck) {
                    $shiftViolationMessage = $isFirstDayShift
                        ? sprintf(
                            $constraint->shiftWorkMinutesViolationMessage,
                            $this->bookingFirstDayTimeMin
                        )
                        : '';
                    $this->context->buildViolation(sprintf(
                        $shiftViolationMessage . $constraint->workMinutesViolationMessage,
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
