<?php

namespace App\Tests\Unit\Service;

use App\DTO\BookingInput;
use App\Entity\Booking;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Repository\BookingRepository;
use App\Repository\ResourceRepository;
use App\Service\BookingCalendarService;
use App\Service\BookingManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

class BookingManagerTest extends TestCase
{
    private ResourceRepository $resourceRepositoryMock;
    private BookingRepository $bookingRepositoryMock;
    private EntityManagerInterface $entityManagerMock;
    private BookingManager $bookingManager;
    private int $bookingTechBreak = 5; // A stub for tech break
    private int $bookingFirstDayTimeMin = 30; // A stub for first day time

    protected function setUp(): void
    {
        $this->resourceRepositoryMock = $this->createMock(ResourceRepository::class);
        $this->bookingRepositoryMock = $this->createMock(BookingRepository::class);
        $this->entityManagerMock = $this->createMock(EntityManagerInterface::class);
        $calendarService = new BookingCalendarService();

        // A stub for Messenger
        $messageBusMock = $this->createMock(MessageBusInterface::class);
        $messageBusMock->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        // A stub for Logger
        $loggerMock = $this->createMock(LoggerInterface::class);

        // A stub for delay
        $bookingPaymentDelay = 5; // 15 minutes in .env, 5 minutes in tests

        $this->bookingManager = new BookingManager(
            $this->resourceRepositoryMock,
            $this->bookingRepositoryMock,
            $this->entityManagerMock,
            $messageBusMock,
            $loggerMock,
            $calendarService,
            $bookingPaymentDelay,
            $this->bookingTechBreak,
            $this->bookingFirstDayTimeMin
        );
    }

    /**
     * Test creating booking successfully and price calculation is correct
     */
    #[AllowMockObjectsWithoutExpectations] // to avoid notices about missing expectations MessageBusInterface & LoggerInterface
    public function testCreateBookingSuccessAndPriceCalculation(): void
    {
        $user = new User();
        $resource = new Resource();
        $resource->setPricePerHour(1500); // $15.00

        $mockUuidV7 = Uuid::v7(); // Generating a UUIDv7 for the resource id

        // Implementing the ID through reflection
        $reflection = new \ReflectionClass($resource);
        $property = $reflection->getProperty('id');
        $property->setValue($resource, $mockUuidV7);

        $start = (new \DateTimeImmutable('next week monday'))
            ->setTime(12, 0, 0);
        $end = $start->modify('+2 hours'); // Exactly 2 hours

        // Filling the DTO
        $bookingInput = new BookingInput();
        $bookingInput->resourceId = $mockUuidV7->toString(); // Passing the string representation of UUIDv7
        $bookingInput->startedAt = $start->format(\DateTimeInterface::ATOM);
        $bookingInput->endDate = $end->format(\DateTimeInterface::ATOM);
        $bookingInput->duration = null;

        // Indicates that there is such a resource in the database
        $this->resourceRepositoryMock
            ->expects($this->once())
            ->method('find')
            ->with($bookingInput->resourceId)
            ->willReturn($resource);

        // Adjusting dates for database checks
        // (such dates should be included in the hasOverlappingBookings method)
        $expectedDbStart = $start->modify("-{$this->bookingTechBreak} minutes");
        $expectedDbEnd = $end->modify("+{$this->bookingTechBreak} minutes");
        $rawStart = $bookingInput->getStartedAtObject('UTC');

        // Indicating that there are no intersections in the database
        $this->bookingRepositoryMock->expects($this->once())
            ->method('hasOverlappingBookings')
            ->with($resource, $expectedDbStart, $expectedDbEnd)
            ->willReturn(false);

        $this->entityManagerMock->expects($this->once())->method('persist');
        $this->entityManagerMock->expects($this->once())->method('flush');

        $booking = $this->bookingManager->createBookingFromParams($user, $bookingInput, $rawStart);

        $this->assertInstanceOf(Booking::class, $booking);
        $this->assertEquals(BookingStatus::PENDING, $booking->getStatus());
        $this->assertEquals(3000, $booking->getTotalPrice()); // 1500 * 2 = 3000
    }

    /**
     * Test creating booking successfully and price rounding in favor of business
     */
    #[AllowMockObjectsWithoutExpectations] // to avoid notices about missing expectations bookingRepositoryMock & entityManagerMock
    public function testCreateBookingPriceRoundingInFavorOfBusiness(): void
    {
        $user = new User();
        $resource = new Resource();
        $resource->setPricePerHour(1000);

        $mockUuidV7 = Uuid::v7(); // Generating a UUIDv7 for the resource id

        // Implementing the ID through reflection
        $reflection = new \ReflectionClass($resource);
        $property = $reflection->getProperty('id');
        $property->setValue($resource, $mockUuidV7);

        $start = (new \DateTimeImmutable('next week monday'))
            ->setTime(12, 0, 0);
        $end = $start->modify('+1 hour 10 minutes'); // 1 hour and 10 minutes

        // Filling the DTO
        $bookingInput = new BookingInput();
        $bookingInput->resourceId = $mockUuidV7->toString(); // Passing the string representation of UUIDv7
        $bookingInput->startedAt = $start->format(\DateTimeInterface::ATOM);
        $bookingInput->endDate = $end->format(\DateTimeInterface::ATOM);
        $bookingInput->duration = null;

        // Indicates that there is such a resource in the database
        $this->resourceRepositoryMock
            ->expects($this->once())
            ->method('find')
            ->with($bookingInput->resourceId)
            ->willReturn($resource);

        $rawStart = $bookingInput->getStartedAtObject('UTC');

        $this->bookingRepositoryMock->method('hasOverlappingBookings')->willReturn(false);

        $booking = $this->bookingManager->createBookingFromParams($user, $bookingInput, $rawStart);

        // The time should be rounded up to 1167 full seconds
        $this->assertEquals(1167, $booking->getTotalPrice());
    }

    /**
     * Test creating booking throws exception on overlap
     */
    #[AllowMockObjectsWithoutExpectations] // to avoid notices about missing expectations bookingRepositoryMock & entityManagerMock
    public function testCreateBookingThrowsExceptionOnOverlap(): void
    {
        $user = new User();
        $resource = new Resource();

        $mockUuidV7 = Uuid::v7(); // Generating a UUIDv7 for the resource id

        // Implementing the ID through reflection
        $reflection = new \ReflectionClass($resource);
        $property = $reflection->getProperty('id');
        $property->setValue($resource, $mockUuidV7);

        $start = (new \DateTimeImmutable('next week monday'))
            ->setTime(12, 0, 0);
        $end = $start->modify('+2 hours');

        // Filling the DTO
        $bookingInput = new BookingInput();
        $bookingInput->resourceId = $mockUuidV7->toString(); // Passing the string representation of UUIDv7
        $bookingInput->startedAt = $start->format(\DateTimeInterface::ATOM);
        $bookingInput->endDate = $end->format(\DateTimeInterface::ATOM);
        $bookingInput->duration = null;

        // Indicates that there is such a resource in the database
        $this->resourceRepositoryMock
            ->expects($this->once())
            ->method('find')
            ->with($bookingInput->resourceId)
            ->willReturn($resource);

        $rawStart = $bookingInput->getStartedAtObject('UTC');

        // Simulating that an intersection has been found
        $this->bookingRepositoryMock->expects($this->once())
            ->method('hasOverlappingBookings')
            ->willReturn(true);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('This time interval is already occupied for the selected resource.');

        $this->bookingManager->createBookingFromParams($user, $bookingInput, $rawStart);
    }

    /**
     * Test creating booking over night successfully and price calculation is correct
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testCreateBookingCarriesOverNightAndCalculatesCorrectPrice(): void
    {
        $user = new User();
        $resource = new Resource();
        $resource->setPricePerHour(1000);

        $mockUuidV7 = Uuid::v7();
        $reflection = new \ReflectionClass($resource);
        $property = $reflection->getProperty('id');
        $property->setValue($resource, $mockUuidV7);

        // Wednesday 18:00:00 (2 hours until the end of the working day)
        $start = new \DateTimeImmutable('next week wednesday 18:00:00', new \DateTimeZone('UTC'));
        $duration = 180; // 3 hours (180 minutes). 2 hours will be spent on Wednesday, 1 hour will be transferred to Thursday morning

        // Filling the DTO
        $bookingInput = new BookingInput();
        $bookingInput->resourceId = $mockUuidV7->toString(); // Passing the string representation of UUIDv7
        $bookingInput->startedAt = $start->format(\DateTimeInterface::ATOM);
        $bookingInput->endDate = null;
        $bookingInput->duration = $duration;

        $this->resourceRepositoryMock->expects($this->once())
            ->method('find')->with($mockUuidV7->toString())->willReturn($resource);

        // expected end-to-end: Thursday 09:00:00
        $expectedEnd = $start->modify('+1 day')->setTime(9, 0, 0);

        // Time borders for the database (considering a technical break of 5 minutes)
        $expectedDbStart = $start->modify('-5 minutes');
        $expectedDbEnd = $expectedEnd->modify('+5 minutes');
        $rawStart = $bookingInput->getStartedAtObject('UTC');

        $this->bookingRepositoryMock->expects($this->once())
            ->method('hasOverlappingBookings')
            ->with($resource, $expectedDbStart, $expectedDbEnd)
            ->willReturn(false);

        $booking = $this->bookingManager->createBookingFromParams($user, $bookingInput, $rawStart);

        $this->assertEquals($start->format('Y-m-d H:i:s'), $booking->getStartedAt()->format('Y-m-d H:i:s'));
        $this->assertEquals($expectedEnd->format('Y-m-d H:i:s'), $booking->getEndedAt()->format('Y-m-d H:i:s'));
        // The price must be strictly 3 business hours in advance: 1000 * 3 = 3000
        $this->assertEquals(3000, $booking->getTotalPrice());
    }

    /**
     * Test creating booking enforces minimum thirty minutes on first day or shift to next working day
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testCreateBookingEnforcesMinimumThirtyMinutesOnFirstDay(): void
    {
        $user = new User();
        $resource = new Resource();
        $resource->setPricePerHour(1000);

        $mockUuidV7 = Uuid::v7();
        $reflection = new \ReflectionClass($resource);
        $property = $reflection->getProperty('id');
        $property->setValue($resource, $mockUuidV7);

        // Friday 19:45:00 (there are only 15 minutes left before closing)
        $start = new \DateTimeImmutable('next week friday 19:45:00', new \DateTimeZone('UTC'));
        $duration = 60; // 1 hour (more than 15 minutes)

        // Filling the DTO
        $bookingInput = new BookingInput();
        $bookingInput->resourceId = $mockUuidV7->toString(); // Passing the string representation of UUIDv7
        $bookingInput->startedAt = $start->format(\DateTimeInterface::ATOM);
        $bookingInput->endDate = null;
        $bookingInput->duration = $duration;

        $this->resourceRepositoryMock->expects($this->once())
            ->method('find')->with($mockUuidV7->toString())->willReturn($resource);

        $rawStart = $bookingInput->getStartedAtObject('UTC');

        // Since 15 min < $bookingFirstDayTimeMin minutes,
        // the start should leave completely on Monday at 08:00 and the end at 09:00
        $expectedStart = $start->modify('+3 days')->setTime(8, 0, 0);
        $expectedEnd = $expectedStart->modify('+1 hour');

        $this->bookingRepositoryMock->method('hasOverlappingBookings')->willReturn(false);

        $booking = $this->bookingManager->createBookingFromParams($user, $bookingInput, $rawStart);

        $this->assertEquals($expectedStart->format('Y-m-d H:i:s'), $booking->getStartedAt()->format('Y-m-d H:i:s'));
        $this->assertEquals($expectedEnd->format('Y-m-d H:i:s'), $booking->getEndedAt()->format('Y-m-d H:i:s'));
    }

    /**
     * Test creating booking throws exception if interval is entirely outside working hours
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testCreateBookingThrowsExceptionIfIntervalIsEntirelyOutsideWorkingHours(): void
    {
        $user = new User();
        $resource = new Resource();

        $mockUuidV7 = Uuid::v7();
        $reflection = new \ReflectionClass($resource);
        $property = $reflection->getProperty('id');
        $property->setValue($resource, $mockUuidV7);

        // Night time (from 02:00 to 05:00)
        $start = new \DateTimeImmutable('next week monday 02:00:00', new \DateTimeZone('UTC'));
        $end = $start->modify('+3 hours');

        // Filling the DTO
        $bookingInput = new BookingInput();
        $bookingInput->resourceId = $mockUuidV7->toString(); // Passing the string representation of UUIDv7
        $bookingInput->startedAt = $start->format(\DateTimeInterface::ATOM);
        $bookingInput->endDate = $end->format(\DateTimeInterface::ATOM);
        $bookingInput->duration = null;

        $this->resourceRepositoryMock->expects($this->once())
            ->method('find')->with($mockUuidV7->toString())->willReturn($resource);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The requested interval is entirely outside of working hours (nights or weekends).');

        // The method should throw an exception at the very beginning of the security check
        $this->bookingManager->createBookingFromParams($user, $bookingInput, $start);
    }
}
