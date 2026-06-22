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
    ) {}
}
