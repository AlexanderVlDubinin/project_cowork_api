<?php

namespace App\Service;

use App\DTO\ResourceInput;
use App\Entity\Resource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

readonly class ResourceService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Create a new resource using the transferred data (DTO - ResourceInput)
     */
    public function createFromInput(ResourceInput $input): Resource
    {
        $resource = new Resource();

        $this->mapInputToEntity($input, $resource);

        $this->entityManager->persist($resource);
        $this->entityManager->flush();

        return $resource;
    }

    /**
     * Update an existing resource using the transferred data (DTO - ResourceInput)
     */
    public function updateFromInput(Resource $resource, ResourceInput $input): Resource
    {
        $existing = $this->entityManager->getRepository(Resource::class)
            ->findOneBy(['title' => $input->title]);

        if ($existing && $existing->getId() !== $resource->getId()) {
            throw new ConflictHttpException('A desk/room with that name already exists');
        }

        $this->mapInputToEntity($input, $resource);
        $this->entityManager->flush();

        return $resource;
    }

    /**
     * Delete an existing resource
     */
    public function delete(Resource $resource): void
    {
        // TODO: Add a check for (active) bookings for this resource.

        $this->entityManager->remove($resource);
        $this->entityManager->flush();
    }

    /**
     * Method for filling in an entity from a DTO
     */
    private function mapInputToEntity(ResourceInput $input, Resource $resource): void
    {
        $resource->setTitle($input->title);
        $resource->setDescription($input->description);
        $resource->setIsActive($input->isActive);
        $resource->setPricePerHour($input->pricePerHour);
        $resource->setType($input->type);
    }
}
