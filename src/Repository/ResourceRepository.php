<?php

namespace App\Repository;

use App\Entity\Resource;
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

    public function findByFilters(?ResourceType $type, bool $isActive): array
    {
        $qb = $this->createQueryBuilder('resources')
            ->where('resources.isActive = :isActive')
            ->setParameter('isActive', $isActive);

        if ($type) {
            $qb->andWhere('resources.type = :type')
                ->setParameter('type', $type->value);
        }

        return $qb->getQuery()->getResult();
    }
}
