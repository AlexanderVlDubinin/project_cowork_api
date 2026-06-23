<?php

namespace App\Repository;

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

    public function findListForAdminByFilters(ResourceListFilterInput $filters): array
    {
        $qb = $this->createQueryBuilder('resources');
        $qb->select('resources AS resource');

        $hasBookingFilters = !is_null($filters->userId)
            || !is_null($filters->startDate)
            || !is_null($filters->endDate)
            || !is_null($filters->status);

        if ($hasBookingFilters) {
            $qb->join('resources.bookings', 'b')
                ->join('b.user', 'u')
                ->andWhere('b.status NOT IN (:excludedStatuses)')
                ->setParameter('excludedStatuses', [BookingStatus::EXPIRED, BookingStatus::CANCELLED, BookingStatus::COMPLETED]);

            $qb->addSelect('IDENTITY(b.user) AS userId')
                ->addSelect('u.email AS userEmail')
                ->addSelect('b.startedAt AS startDate')
                ->addSelect('b.endedAt AS endDate')
                ->addSelect('b.status AS status');

            if (!is_null($filters->userId)) {
                $qb->andWhere('b.user = :userId')
                    ->setParameter('userId', $filters->userId);
            }

            if (!is_null($filters->startDate)) {
                $qb->andWhere('b.startedAt >= :startDate')
                    ->setParameter('startDate', $filters->startDate);
            }

            if (!is_null($filters->endDate)) {
                $qb->andWhere('b.endedAt <= :endDate')
                    ->setParameter('endDate', $filters->endDate);
            }

            if (!is_null($filters->status)) {
                $qb->andWhere('b.status = :status')
                    ->setParameter('status', $filters->status);
            }
        }

        if (!is_null($filters->type)) {
            $qb->andWhere('resources.type = :type')
                ->setParameter('type', $filters->type);
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
