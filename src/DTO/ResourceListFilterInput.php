<?php

namespace App\DTO;

use App\Enum\BookingStatus;
use App\Enum\ResourceType;
use App\Validator\ValidResourceFilter;
use Symfony\Component\Validator\Constraints as Assert;

#[ValidResourceFilter]
class ResourceListFilterInput
{
    #[Assert\NotBlank(message: 'Start date is required')]
    #[Assert\DateTime(format: \DateTimeInterface::ATOM, message: 'Start date must be in ATOM format (ISO 8601)')]
    public string $startDate;

    #[Assert\DateTime(format: \DateTimeInterface::ATOM, message: 'End date must be in ATOM format (ISO 8601)')]
    public ?string $endDate = null;

    public ?int $duration = null;

    public ?ResourceType $type = null;

    public ?bool $active = null;

    public ?BookingStatus $status = null;

    #[Assert\Uuid]
    public ?string $userId = null;

    #[Assert\GreaterThanOrEqual(1, message: 'Page number must be at least 1')]
    public ?int $page = null;

    #[Assert\GreaterThanOrEqual(1, message: 'Limit must be at least 1')]
    public ?int $limit = null;
}
