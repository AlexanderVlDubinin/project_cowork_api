<?php

namespace App\DTO;

use App\Validator\ValidBookingDates;
use Symfony\Component\Validator\Constraints as Assert;

#[ValidBookingDates]
class BookingInput
{
    #[Assert\NotBlank(message: 'Resource ID is required')]
    #[Assert\Uuid(message: 'Resource ID must be a valid UUID')]
    public string $resourceId;

    #[Assert\NotBlank(message: 'Start date is required')]
    #[Assert\DateTime(format: \DateTimeInterface::ATOM, message: 'Date must be in ATOM format (ISO 8601)')]
    public string $startedAt;

    #[Assert\DateTime(format: \DateTimeInterface::ATOM, message: 'End date must be in ATOM format (ISO 8601)')]
    public ?string $endDate = null;

    #[Assert\GreaterThanOrEqual(30, message: 'Duration must be at least 30 minutes')]
    public ?int $duration = null;

    public function getStartedAtObject($tz = ''): \DateTimeImmutable
    {
        $startedAt = new \DateTimeImmutable($this->startedAt);

        if ($tz) {
            $startedAt = $startedAt->setTimezone(new \DateTimeZone($tz));
        }

        return $startedAt;
    }
}
