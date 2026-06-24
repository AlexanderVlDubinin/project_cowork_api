<?php

namespace App\Tests\Unit\Service;

use App\Entity\Booking;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Repository\BookingRepository;
use App\Service\BookingManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class BookingManagerTest extends TestCase
{
    private BookingRepository $bookingRepositoryMock;
    private EntityManagerInterface $entityManagerMock;
    private BookingManager $bookingManager;

    protected function setUp(): void
    {
        $this->bookingRepositoryMock = $this->createMock(BookingRepository::class);
        $this->entityManagerMock = $this->createMock(EntityManagerInterface::class);

        $this->bookingManager = new BookingManager(
            $this->bookingRepositoryMock,
            $this->entityManagerMock
        );
    }

    public function testCreateBookingSuccessAndPriceCalculation(): void
    {
        $user = new User();
        $resource = new Resource();
        $resource->setPricePerHour(1500); // $15.00

        //$start = new \DateTimeImmutable('2026-06-18T10:00:00Z');
        //$end = new \DateTimeImmutable('2026-06-18T12:00:00Z'); // Exactly 2 hours
        $start = new \DateTimeImmutable('+2 hours'); // The date depends on the current one
        $end = new \DateTimeImmutable('+4 hours'); // Exactly 2 hours

        // Indicating that there are no intersections in the database
        $this->bookingRepositoryMock->expects($this->once())
            ->method('hasOverlappingBookings')
            ->with($resource, $start, $end)
            ->willReturn(false);

        $this->entityManagerMock->expects($this->once())->method('persist');
        $this->entityManagerMock->expects($this->once())->method('flush');

        $booking = $this->bookingManager->createBooking($user, $resource, $start, $end);

        $this->assertInstanceOf(Booking::class, $booking);
        $this->assertEquals(BookingStatus::PENDING, $booking->getStatus());
        $this->assertEquals(3000, $booking->getTotalPrice()); // 1500 * 2 = 3000
    }

    #[AllowMockObjectsWithoutExpectations] // to avoid notices about missing expectations bookingRepositoryMock & entityManagerMock
    public function testCreateBookingPriceRoundingInFavorOfBusiness(): void
    {
        $user = new User();
        $resource = new Resource();
        $resource->setPricePerHour(1000);

        //$start = new \DateTimeImmutable('2026-06-18T10:00:00Z');
        //$end = new \DateTimeImmutable('2026-06-18T11:10:00Z'); // 1 hour and 10 minutes
        $start = new \DateTimeImmutable('+2 hours'); // The date depends on the current one
        $end = new \DateTimeImmutable('+3 hours 10 minutes'); // 1 hour and 10 minutes

        $this->bookingRepositoryMock->method('hasOverlappingBookings')->willReturn(false);

        $booking = $this->bookingManager->createBooking($user, $resource, $start, $end);

        // The time should be rounded up to 1167 full seconds
        $this->assertEquals(1167, $booking->getTotalPrice());
    }

    #[AllowMockObjectsWithoutExpectations] // to avoid notices about missing expectations bookingRepositoryMock & entityManagerMock
    public function testCreateBookingThrowsExceptionOnOverlap(): void
    {
        $user = new User();
        $resource = new Resource();
        //$start = new \DateTimeImmutable('2026-06-18T10:00:00Z');
        //$end = new \DateTimeImmutable('2026-06-18T12:00:00Z');
        $start = new \DateTimeImmutable('+2 hours');
        $end = new \DateTimeImmutable('+4 hours');

        // Simulating that an intersection has been found
        $this->bookingRepositoryMock->expects($this->once())
            ->method('hasOverlappingBookings')
            ->willReturn(true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('This time interval is already occupied for the selected resource.');

        $this->bookingManager->createBooking($user, $resource, $start, $end);
    }

    /*
    public function testSomething(): void
    {
        $this->assertTrue(true);
    }
    */
}
