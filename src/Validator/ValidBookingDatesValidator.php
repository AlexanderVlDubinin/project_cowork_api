<?php

namespace App\Validator;

use App\Dto\BookingInput;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class ValidBookingDatesValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        /** @var BookingInput $value */
        /** @var ValidBookingDates $constraint */

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $startedAt = $value->getStartedAtObject('UTC');
        $endedAt = $value->getEndedAtObject('UTC');

        if ($startedAt < $now) {
            $this->context->buildViolation($constraint->pastMessage)
                ->atPath('startedAt')
                ->addViolation();
        }

        if ($endedAt <= $startedAt) {
            $this->context->buildViolation($constraint->message)
                ->atPath('endedAt')
                ->addViolation();
        }
    }
}
