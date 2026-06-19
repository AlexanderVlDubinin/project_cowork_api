<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class ValidBookingDates extends Constraint
{
    public string $message = 'The end date of the booking must be later than the start date.';
    public string $pastMessage = 'You cannot book a resource in the past.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
