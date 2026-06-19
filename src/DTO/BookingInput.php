<?php

namespace App\DTO;

use App\Validator\ValidBookingDates;
use Symfony\Component\Validator\Constraints as Assert;

#[ValidBookingDates]
class BookingInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $resourceId;

    #[Assert\NotBlank]
    // #[Assert\DateTime(\DateTimeInterface::ATOM)]
    #[Assert\DateTime(format: 'Y-m-d\TH:i:sP', message: 'Date must be in ATOM format (ISO 8601)')]
    public string $startedAt;

    #[Assert\NotBlank]
    // #[Assert\DateTime(\DateTimeInterface::ATOM)]
    #[Assert\DateTime(format: 'Y-m-d\TH:i:sP', message: 'Date must be in ATOM format (ISO 8601)')]
    // #[Assert\GreaterThan(propertyPath: 'startedAt')] // Not needed, custom validator ValidBookingDatesValidator handles this
    public string $endedAt;

    public function getStartedAtObject($tz = ''): \DateTimeImmutable
    {
        $startedAt =new \DateTimeImmutable($this->startedAt);

        if ($tz) {
            $startedAt->setTimezone(new \DateTimeZone($tz));
        }

        return $startedAt;
    }

    public function getEndedAtObject($tz = ''): \DateTimeImmutable
    {
        $endedAt = new \DateTimeImmutable($this->endedAt);

        if ($tz) {
            $endedAt->setTimezone(new \DateTimeZone($tz));
        }

        return $endedAt;
    }
}
