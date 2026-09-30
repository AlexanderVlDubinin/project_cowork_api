<?php

namespace App\Repository;

use App\DTO\ResourceListAdminFilterInput;
use App\DTO\ResourceListFilterInput;
use App\Entity\Resource;
use App\Enum\BookingStatus;
use App\Enum\ResourceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Resource>
 */
class ResourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Resource::class);
    }

    public function findListForAdminByFilters(ResourceListAdminFilterInput $filters): array
    {
        $qb = $this->createQueryBuilder('resources');

        if (!is_null($filters->type)) {
            $qb->andWhere('resources.type = :type')
                ->setParameter('type', $filters->type);
        }

        if (!is_null($filters->query)) {
            $query = str_replace(['|', '%', '_'], ['||', '|%', '|_'], $filters->query);
            // TODO: add indexes for LOWER() and LIKE '%text%' (pg_trgm, gin OR gist)
            $qb->andWhere("LOWER(resources.title) LIKE LOWER(:query) ESCAPE '|'
               OR LOWER(resources.description) LIKE LOWER(:query) ESCAPE '|'")
                ->setParameter('query', "%{$query}%");
        }

        if (!is_null($filters->active)) {
            $qb->andWhere('resources.isActive = :active')
                ->setParameter('active', $filters->active);
        }

        return $qb->getQuery()->getResult();
    }

    public function findListForClientByFilters(?ResourceListFilterInput $filters): array
    {
        $type = $filters->type ?? null;

        $qb = $this->createQueryBuilder('resources')
            ->where('resources.isActive = :isActive')
            ->setParameter('isActive', true);

        if ($type) {
            $qb->andWhere('resources.type = :type')
                ->setParameter('type', $type->value);
        }

        return $qb->getQuery()->getResult();
    }
}
