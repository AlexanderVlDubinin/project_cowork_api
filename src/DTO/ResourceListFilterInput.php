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

        //#[Assert\DateTime(format: \DateTimeInterface::ATOM)]
        #[Assert\DateTime(format: \DateTimeInterface::ATOM, message: 'Start date must be in ATOM format (ISO 8601)')]
        public readonly ?string $startDate = null,

        //#[Assert\DateTime(format: \DateTimeInterface::ATOM)]
        #[Assert\DateTime(format: \DateTimeInterface::ATOM, message: 'End date must be in ATOM format (ISO 8601)')]
        #[Assert\GreaterThan(
            propertyPath: 'startDate',
            message: "The end date must be later than the start date."
        )]
        public readonly ?string $endDate = null,

        public readonly ?BookingStatus $status = null,

        #[Assert\Uuid]
        public readonly ?string $userId = null,
    ) {}

    /*
    public function hasData(): bool
    {
        return !empty(array_filter(get_object_vars($this), fn($val) => $val !== null));
    }
    */
}
