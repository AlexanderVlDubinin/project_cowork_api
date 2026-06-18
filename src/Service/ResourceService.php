<?php

namespace App\Service;

use App\DTO\ResourceInput;
use App\Entity\Resource;
use App\Enum\ResourceType;

class ResourceService
{
    /**
     * Method for filling in an entity from a DTO
     */
    public function mapInputToEntity(ResourceInput $input, Resource $resource): void
    {
        $resource->setTitle($input->title);
        $resource->setDescription($input->description);
        $resource->setIsActive($input->isActive);
        $resource->setPricePerHour($input->pricePerHour);

        // Turning the validated string back into a Backed Enum object
        $resource->setType(ResourceType::from($input->type->value));
    }
}
