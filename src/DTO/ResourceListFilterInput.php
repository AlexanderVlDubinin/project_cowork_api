<?php

namespace App\DTO;

use App\Enum\BookingStatus;
use App\Enum\ResourceType;
use Symfony\Component\Validator\Constraints as Assert;

class ResourceListFilterInput
{
    public function __construct(
        public readonly ?ResourceType $type = null,

        public readonly ?bool $active = null,

        #[Assert\DateTime(format: \DateTimeInterface::ATOM, message: 'Start date must be in ATOM format (ISO 8601)')]
        public ?string $startDate = null,

        #[Assert\DateTime(format: \DateTimeInterface::ATOM, message: 'End date must be in ATOM format (ISO 8601)')]
        public readonly ?string $endDate = null,

        #[Assert\GreaterThanOrEqual(30, message: 'Duration must be at least 30 minutes')]
        public ?int $duration = null,

        public readonly ?BookingStatus $status = null,

        #[Assert\Uuid]
        public readonly ?string $userId = null,

        #[Assert\GreaterThanOrEqual(1, message: 'Page number must be at least 1')]
        public readonly ?int $page = null,

        #[Assert\GreaterThanOrEqual(1, message: 'Limit must be at least 1')]
        public readonly ?int $limit = null,
    ) {}

    /*
    public function hasData(): bool
    {
        return !empty(array_filter(get_object_vars($this), fn($val) => $val !== null));
    }
    */

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
}
