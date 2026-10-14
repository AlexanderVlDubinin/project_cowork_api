<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class ValidBookingDates extends Constraint
{
    public string $durationMinMessage = 'Duration must be at least {{ limit }} minutes.';
    public string $startEndDateOrderMessage = 'The end date of the booking must be later than the start date.';
    public string $startInPastMessage = 'Start date must be in the future.';
    public string $outsideIntervalMessage = 'The requested interval is entirely outside of working hours (nights or weekends).';
    public string $shiftStartEndViolationMessage = ' This rule was violated after the shifting the start time (to the beginning of the working day - 08:00) due to lack of time on the first day (<%d minutes).';
    public string $afterShiftStartEndMessage = 'After shifting the start time due to insufficient time on the first day (<%d mins), the start time moved past or equal to the end time.';
    public string $minStartEndIntervalMessage = 'The interval between start date and end date must be at least %d minutes.';
    public string $shiftWorkMinutesViolationMessage = 'The start was shifted (to the beginning of the working day - 08:00) due to lack of time on the first day (<%d minutes). ';
    public string $workMinutesViolationMessage = 'The clear working time (%d mins) between start (%s) and end (%s) date is less than requested duration (%d mins).';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
