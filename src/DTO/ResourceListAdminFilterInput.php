<?php

namespace App\DTO;

use App\Enum\ResourceType;
use Symfony\Component\Validator\Constraints as Assert;

readonly class ResourceListAdminFilterInput
{
    public function __construct(
        public ?ResourceType $type = null,

        #[Assert\Length(min: 3, minMessage: 'Query must be at least 3 characters long')]
        #[Assert\Length(max: 255, maxMessage: 'Query must be at most 255 characters long')]
        public ?string       $query = null,

        public ?bool         $active = null,

        #[Assert\GreaterThanOrEqual(1, message: 'Page number must be at least 1')]
        public ?int          $page = null,

        #[Assert\GreaterThanOrEqual(1, message: 'Limit must be at least 1')]
        public ?int          $limit = null,
    ) {}
}
