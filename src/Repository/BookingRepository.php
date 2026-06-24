<?php

namespace App\Repository;

use App\DTO\BookingListFilterInput;
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
            ->setParameter('excludedStatuses', [
                BookingStatus::FAILED,
                BookingStatus::EXPIRED,
                BookingStatus::CANCELLED,
                BookingStatus::COMPLETED
            ]);

        return $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function findBookingsList(
        ?User $user,
        ?BookingListFilterInput $filters,
        bool $isAdmin = false,
        string $orderBy = 'DESC'
    ): array
    {
        $userId = $user?->getId();

        $resourceId = $startDate = $endDate = $status = null;
        if ($isAdmin) {
            $userId = $filters->userId ?? null;
            $resourceId = $filters->resourceId ?? null;
            $startDate = $filters->startDate ?? null;
            $endDate = $filters->endDate ?? null;
            $status = $filters->status ?? null;
        }

        $qb = $this->createQueryBuilder('b');

        if ($isAdmin) {
            $qb->select([
                'b.id',
                'b.startedAt',
                'b.endedAt',
                'b.status',
                'b.totalPrice',
                'b.createdAt',
                'u.id AS userId',
                'u.email AS userEmail',
                'r.id AS resourceId',
                'r.title AS resourceTitle'
            ]);
        } else {
            $qb->select([
                'b.id',
                'b.startedAt',
                'b.endedAt',
                'b.status',
                'b.totalPrice',
                'r.title AS resourceTitle'
            ]);
        }

        $qb->join('b.resource', 'r')
            ->join('b.user', 'u');

        if ($userId) {
            $qb->where('b.user = :user')
                ->setParameter('user', $userId);
        }

        if ($resourceId) {
            $qb->andWhere('b.resource = :resource')
                ->setParameter('resource', $resourceId);
        }

        if ($startDate) {
            $qb->andWhere('b.startedAt >= :startDate')
                ->setParameter('startDate', $startDate);
        }

        if ($endDate) {
            $qb->andWhere('b.endedAt <= :endDate')
                ->setParameter('endDate', $endDate);
        }

        if ($status) {
            $qb->andWhere('b.status = :status')
                ->setParameter('status', $status);
        }

        $qb->andWhere('b.status NOT IN (:excludedStatuses)')
            ->setParameter('excludedStatuses', [
                BookingStatus::FAILED,
                BookingStatus::EXPIRED,
                BookingStatus::CANCELLED,
                BookingStatus::COMPLETED,
                BookingStatus::NO_SHOW
            ])
            ->orderBy('b.createdAt', $orderBy);

        return $qb->getQuery()->getArrayResult();
    }

    public function findBookingsForDate(Resource $resource, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.resource = :resource')
            ->andWhere('b.startedAt < :end')
            ->andWhere('b.endedAt > :start')
            ->setParameter('resource', $resource)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('b.startedAt', 'ASC') // Important for the gap search algorithm
            ->getQuery()
            ->getResult();
    }
}
