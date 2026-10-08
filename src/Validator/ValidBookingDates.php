<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class ValidBookingDates extends Constraint
{
    public string $message = 'The end date of the booking must be later than the start date.';
    public string $outsideIntervalMessage = 'The requested interval is entirely outside of working hours (nights or weekends).';
    public string $shiftStartEndViolationMessage = ' This rule was violated after the shifting the start time (to the beginning of the working day - 08:00) due to lack of time on the first day (<30 minutes).';
    public string $afterShiftStartEndMessage = 'After shifting the start time due to insufficient time on the first day (<30 mins), the start time moved past or equal to the end time.';
    public string $minStartEndIntervalMessage = 'The interval between start date and end date must be at least 30 minutes.';
    public string $shiftWorkMinutesViolationMessage = 'The start was shifted (to the beginning of the working day - 08:00) due to lack of time on the first day (<30 minutes). ';
    public string $workMinutesViolationMessage = 'The clear working time (%d mins) between start (%s) and end (%s) date is less than requested duration (%d mins).';
    public string $pastMessage = 'You cannot book a resource in the past.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
