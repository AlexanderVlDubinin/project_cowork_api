<?php

namespace App\DTO;

use App\Enum\BookingStatus;
use Symfony\Component\Validator\Constraints as Assert;

class BookingListFilterInput
{
    public function __construct(
        #[Assert\Uuid(message: 'The user ID must have a Uuid type.')]
        public readonly ?string $userId = null,

        #[Assert\Uuid(message: 'The resource ID must have a Uuid type.')]
        public readonly ?string $resourceId = null,

        #[Assert\DateTime(format: \DateTimeInterface::ATOM, message: 'Start date must be in ATOM format (ISO 8601)')]
        public ?string $startDate = null,

        #[Assert\DateTime(format: \DateTimeInterface::ATOM, message: 'End date must be in ATOM format (ISO 8601)')]
        public readonly ?string $endDate = null,

        public readonly ?BookingStatus $status = null,

        #[Assert\Positive(message: 'The limit must be a positive integer.')]
        public readonly ?int $limit = 10,

        #[Assert\Positive(message: 'The page must be a positive integer.')]
        public readonly ?int $page = 1
    ) {}

    #[Assert\IsFalse(message: 'Start date must be in the future')]
    public function isStartDateInPast(): bool
    {
        if (empty($this->startDate)) {
            return false; // no error when empty startDate
        }
        return new \DateTimeImmutable($this->startDate) <= new \DateTimeImmutable('now');
    }

    #[Assert\IsFalse(message: 'The end date must be later than the start date.')]
    public function isEndDateBeforeStartDate(): bool
    {
        if (empty($this->startDate) || empty($this->endDate)) {
            return false; // skip if when empty startDate or endDate
        }
        return $this->endDate <= $this->startDate;
    }

    public function bookingsOutputSort($bookingsOutput): array
    {
        usort($bookingsOutput, function ($a, $b) {
            $startA = $a['startedAt'] ?? '';
            $startB = $b['startedAt'] ?? '';

            return $startB <=> $startA;
        });

        return $bookingsOutput;
    }
}
