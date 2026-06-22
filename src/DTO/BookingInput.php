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
    #[Assert\DateTime(format: \DateTimeInterface::ATOM, message: 'Date must be in ATOM format (ISO 8601)')]
    public string $startedAt;

    #[Assert\NotBlank]
    #[Assert\GreaterThanOrEqual(30, message: 'Duration must be at least 30 minutes')]
    public int $duration;

    public function getStartedAtObject($tz = ''): \DateTimeImmutable
    {
        $startedAt =new \DateTimeImmutable($this->startedAt);

        if ($tz) {
            $startedAt->setTimezone(new \DateTimeZone($tz));
        }

        return $startedAt;
    }

    public function getEndedAtObject(\DateTimeImmutable $startedAt, int $duration): \DateTimeImmutable
    {
        return $startedAt->modify("+{$duration} minutes");
    }
}
