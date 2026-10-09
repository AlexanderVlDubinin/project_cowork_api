<?php

namespace App\Tests\Unit\Service;

use App\DTO\ResourceListFilterInput;
use App\Entity\Booking;
use App\Entity\Resource;
use App\Repository\BookingRepository;
use App\Repository\ResourceRepository;
use App\Service\BookingCalendarService;
use App\Service\ResourceAvailabilityService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Serializer\Serializer;

class ResourceAvailabilityTest extends TestCase
{
    private ResourceRepository&Stub $resourceRepositoryStub;
    private BookingRepository&Stub $bookingRepositoryStub;
    private Serializer&Stub $serializerStub;
    private ResourceAvailabilityService $service;
    private int $bookingTechBreak = 5;

    protected function setUp(): void
    {
        $this->resourceRepositoryStub = $this->createStub(ResourceRepository::class);
        $this->bookingRepositoryStub = $this->createStub(BookingRepository::class);
        $calendarService = new BookingCalendarService();
        $this->serializerStub = $this->createStub(Serializer::class);

        // A stub for break
        //$bookingTechBreak = 5;

        $this->service = new ResourceAvailabilityService(
            $this->resourceRepositoryStub,
            $this->bookingRepositoryStub,
            $calendarService,
            $this->serializerStub,
            $this->bookingTechBreak
        );
    }

    // Resource entity creation helper for tests
    private function createTestResource(string $uuidString, int $pricePerHour = 1000): Resource
    {
        $resource = $this->createStub(Resource::class);
        $uuid = Uuid::fromString($uuidString);
        $resource->method('getId')->willReturn($uuid);
        $resource->method('getPricePerHour')->willReturn($pricePerHour);
        return $resource;
    }

    // Booking mock creation helper for tests
    private function createMockBooking(string $start, string $end, Resource $resource): Booking
    {
        $booking = $this->createStub(Booking::class);
        $booking->method('getStartedAt')->willReturn(new \DateTimeImmutable($start));
        $booking->method('getEndedAt')->willReturn(new \DateTimeImmutable($end));
        $booking->method('getResource')->willReturn($resource);
        return $booking;
    }

    /**
     * TEST 1: The resource is completely free (no bookings).
     * Booking for next week monday at 12:00 (guaranteed in the future and during business hours).
     * The slot must start at the specified time and last exactly for a duration.
     */
    public function testFindNearestIntervalWhenResourceIsCompletelyFree(): void
    {
        $resourceId = Uuid::v7()->toString();
        $resource = $this->createTestResource($resourceId);

        // Setting the basic filters
        $dateTime = new \DateTimeImmutable('next week monday 12:00:00', new \DateTimeZone('UTC'));
        $filters = new ResourceListFilterInput($dateTime->format(\DateTimeInterface::ATOM));
        $filters->duration = 30;

        // Configuring repository stubs
        $this->resourceRepositoryStub->method('findListForClientByFilters')->willReturn([$resource]);
        $this->bookingRepositoryStub->method('findBookingsForResourcesAndDate')->willReturn([]);

        // Simulating the normalization of the serializer (turning an object into an array)
        $this->serializerStub->method('normalize')->willReturn([
            'id' => $resourceId,
            'pricePerHour' => 1000
        ]);

        $result = $this->service->getAvailableResourcesData($filters);

        $this->assertCount(1, $result);
        $this->assertEquals($resourceId, $result[0]['id']);
        $this->assertArrayHasKey('nearest_slot', $result[0]);

        //The start time must be exactly the same as the requested 12:00 a.m.
        $startSlot = new \DateTimeImmutable($result[0]['nearest_slot']['start']);
        $this->assertEquals('12:00:00', $startSlot->format('H:i:s'));

        // Checking the calculations of time and price
        $this->assertEquals(30, $result[0]['total_time']);
        $this->assertEquals(500, $result[0]['total_price']); // 1000 * 0.5 hours = 500
    }

    /**
     * ТЕСТ 2: The resource is completely free (no bookings).
     * The request is for early next week monday morning (05:00).
     * The system should move the pointer to the beginning of the working day (08:00).
     */
    public function testFindNearestIntervalShiftsToWorkStartIfEarly(): void
    {
        $resourceId = Uuid::v7()->toString();
        $resource = $this->createTestResource($resourceId);

        // Setting the basic filters
        $dateTime = new \DateTimeImmutable('next week monday 05:00:00', new \DateTimeZone('UTC'));
        $filters = new ResourceListFilterInput($dateTime->format(\DateTimeInterface::ATOM));
        $filters->duration = 60;

        // Configuring repository stubs
        $this->resourceRepositoryStub->method('findListForClientByFilters')->willReturn([$resource]);
        $this->bookingRepositoryStub->method('findBookingsForResourcesAndDate')->willReturn([]);
        $this->serializerStub->method('normalize')->willReturn(['id' => $resourceId]);

        $result = $this->service->getAvailableResourcesData($filters);

        $this->assertCount(1, $result);
        $startSlot = new \DateTimeImmutable($result[0]['nearest_slot']['start']);

        // The start time must be shifted to 08:00 a.m. on the current day.
        $this->assertEquals('08:00:00', $startSlot->format('H:i:s'));
    }

    /**
     * ТЕСТ 3: The resource is completely free (no bookings).
     * The request is for next week monday evening (20:30).
     * The system should move the pointer to the beginning of the next working day (Tuesday 08:00).
     */
    public function testFindNearestIntervalReturnsEmptyIfAfterWorkEnd(): void
    {
        $resourceId = Uuid::v7()->toString();
        $resource = $this->createTestResource($resourceId);

        $dateTime = new \DateTimeImmutable('next week monday 20:30:00', new \DateTimeZone('UTC'));
        $filters = new ResourceListFilterInput($dateTime->format(\DateTimeInterface::ATOM));
        $filters->endDate = null;

        $this->resourceRepositoryStub->method('findListForClientByFilters')->willReturn([$resource]);

        $result = $this->service->getAvailableResourcesData($filters);

        $expectedDate = new \DateTimeImmutable('next week tuesday 08:00:00', new \DateTimeZone('UTC'));

        $this->assertEquals($expectedDate->format(\DateTimeInterface::ATOM), $result[0]['nearest_slot']['start']);
    }

    /**
     * TEST 4: Search for a slot "in the gap" between existing bookings.
     * Booking 1: 10:00 - 11:00. Booking 2: 12:00 - 13:00.
     * Request for 45 minutes from 10:00.
     * The gap is released at 11:05 (11:00 + 5-min break).
     * The 11:05 - 11:50 slot is placed before Booking 2 (ends before 11:55 to leave a 5-minute break).
     */
    public function testFindNearestIntervalFitsInGapBetweenBookings(): void
    {
        $resourceId = Uuid::v7()->toString();
        $resource = $this->createTestResource($resourceId);

        // Base point
        $baseDate = new \DateTimeImmutable('next week monday 10:00:00', new \DateTimeZone('UTC'));

        $filters = new ResourceListFilterInput($baseDate->format(\DateTimeInterface::ATOM));
        $filters->duration = 45;
        $filters->endDate = $baseDate->modify('+5 hours')->format(\DateTimeInterface::ATOM);

        // Booking 1
        $booking1 = $this->createMockBooking(
            $baseDate->format(\DateTimeInterface::ATOM),
            $baseDate->setTime(11, 0, 0)->format(\DateTimeInterface::ATOM),
            $resource
        );
        // Booking 2
        $booking2 = $this->createMockBooking(
            $baseDate->setTime(12, 0, 0)->format(\DateTimeInterface::ATOM),
            $baseDate->setTime(13, 0, 0)->format(\DateTimeInterface::ATOM),
            $resource
        );

        $this->resourceRepositoryStub->method('findListForClientByFilters')->willReturn([$resource]);
        $this->bookingRepositoryStub->method('findBookingsForResourcesAndDate')->willReturn([$booking1, $booking2]);
        $this->serializerStub->method('normalize')->willReturn(['id' => $resourceId]);

        $result = $this->service->getAvailableResourcesData($filters);

        $this->assertCount(1, $result);
        $startSlot = new \DateTimeImmutable($result[0]['nearest_slot']['start']);
        $endSlot = new \DateTimeImmutable($result[0]['nearest_slot']['end']);
        // Start is expecting at 11:05 (End of Booking 1 + 5 minutes technical break)
        $this->assertEquals('11:05:00', $startSlot->format('H:i:s'));
        $this->assertEquals('11:50:00', $endSlot->format('H:i:s'));
    }

    /**
     * TEST 5: The slot does not fit into the gap due to a technical break.
     * Booking 1: 10:00 - 11:00. Booking 2: 11:40 - 12:40.
     * Free space: 11:00 - 11:40 (40 minutes).
     * The client wants to book for 35 minutes.
     * 35 minutes of booking + 5 minutes of break = 40 minutes. The slot is scheduled to start at 11:05 and end at 11:40
     * But according to the code: 11:05 + 35m + 5m break before the next one = 11:45 (which is more than 11:40, is superimposed on Booking 2).
     * This means that the algorithm must reschedule the slot for the time AFTER Booking 2.
     */
    public function testFindNearestIntervalSkipsSmallGapDueToTechBreak(): void
    {
        $resourceId = Uuid::v7()->toString();
        $resource = $this->createTestResource($resourceId);

        // Base point
        $baseDate = new \DateTimeImmutable('next week monday 10:00:00', new \DateTimeZone('UTC'));

        $filters = new ResourceListFilterInput($baseDate->format(\DateTimeInterface::ATOM));
        $filters->duration = 35;
        $filters->endDate = $baseDate->modify('+5 hours')->format(\DateTimeInterface::ATOM);

        // Booking 1
        $booking1 = $this->createMockBooking(
            $baseDate->setTime(10, 0, 0)->format(\DateTimeInterface::ATOM),
            $baseDate->setTime(11, 0, 0)->format(\DateTimeInterface::ATOM),
            $resource
        );
        // Booking 2
        $booking2 = $this->createMockBooking(
            $baseDate->setTime(11, 40, 0)->format(\DateTimeInterface::ATOM),
            $baseDate->setTime(12, 40, 0)->format(\DateTimeInterface::ATOM),
            $resource
        );

        $this->resourceRepositoryStub->method('findListForClientByFilters')->willReturn([$resource]);
        $this->bookingRepositoryStub->method('findBookingsForResourcesAndDate')->willReturn([$booking1, $booking2]);
        $this->serializerStub->method('normalize')->willReturn(['id' => $resourceId]);

        $result = $this->service->getAvailableResourcesData($filters);

        $startSlot = $result[0]['nearest_slot']['start'];
        $slotAfterBooking2 = $baseDate->setTime(12, 45, 0)->format(\DateTimeInterface::ATOM);

        $this->assertCount(1, $result);
        // The gap between bookings has been missed. The slot must be assigned after booking a 2 + 5-minute break.
        $this->assertEquals($startSlot, $slotAfterBooking2);
    }
}
