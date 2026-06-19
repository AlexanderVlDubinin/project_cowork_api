<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\BookingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Booking>
 */
class BookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Booking::class);
    }

    public function hasOverlappingBookings(Resource $resource, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        $qb = $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.resource = :resource')
            ->andWhere('b.startedAt < :end')
            ->andWhere('b.endedAt > :start')
            ->andWhere('b.status NOT IN (:excludedStatuses)')
            ->setParameter('resource', $resource)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('excludedStatuses', [BookingStatus::EXPIRED, BookingStatus::CANCELLED, BookingStatus::COMPLETED]);

        return $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function findClientBookings(User $user, string $orderBy = 'DESC'): array
    {
        return $this->createQueryBuilder('b')
            ->select('b.id, u.id AS userId, r.id AS resourceId, b.startedAt, b.endedAt, b.status, b.totalPrice, b.createdAt')
            ->join('b.user', 'u')
            ->join('b.resource', 'r')
            ->where('b.user = :user')
            ->andWhere('b.status NOT IN (:excludedStatuses)')
            ->setParameter('user', $user)
            ->setParameter('excludedStatuses', [BookingStatus::EXPIRED, BookingStatus::CANCELLED, BookingStatus::COMPLETED])
            ->orderBy('b.createdAt', $orderBy)
            ->getQuery()
            ->getArrayResult();
    }
}
