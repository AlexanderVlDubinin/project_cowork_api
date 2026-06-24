<?php

namespace App\Tests\Unit\Service;

use App\DTO\ResourceListFilterInput;
use App\Entity\Booking;
use App\Entity\Resource;
use App\Repository\BookingRepository;
use App\Service\ResourceAvailabilityService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ResourceAvailabilityTest extends TestCase
{
    private BookingRepository&Stub $bookingRepositoryStub;
    private ResourceAvailabilityService $service;

    protected function setUp(): void
    {
        $this->bookingRepositoryStub = $this->createStub(BookingRepository::class);
        $this->service = new ResourceAvailabilityService($this->bookingRepositoryStub);
    }

    // Resource entity creation helper for tests
    private function createTestResource(?string $uuidString = null): Resource
    {
        $resource = $this->createStub(Resource::class);
        $uuid = $uuidString ? Uuid::fromString($uuidString) : Uuid::v7();
        $resource->method('getId')->willReturn($uuid);
        return $resource;
    }

    // Booking mock creation helper for tests
    private function createMockBooking(string $start, string $end): Booking
    {
        $booking = $this->createStub(Booking::class);
        $booking->method('getStartedAt')->willReturn(new \DateTimeImmutable($start));
        $booking->method('getEndedAt')->willReturn(new \DateTimeImmutable($end));
        return $booking;
    }

    /**
     * TEST 1: The resource is completely free (no bookings).
     * Booking for tomorrow at 12:00 (guaranteed in the future and during business hours).
     * The slot must start at the specified time and last exactly for a duration.
     */
    public function testFindNearestIntervalWhenResourceIsCompletelyFree(): void
    {
        $resourceId = Uuid::v7()->toString();
        $resource = $this->createTestResource($resourceId);

        $filters = new ResourceListFilterInput();
        $dateTime = (new \DateTimeImmutable('tomorrow 12:00:00', new \DateTimeZone('UTC')));
        $filters->startDate = $dateTime->format(\DateTimeInterface::ATOM);
        $filters->duration = 30;

        $this->bookingRepositoryStub->method('findBookingsForDate')->willReturn([]);

        $result = $this->service->findNearestIntervals([$resource], $filters);

        $this->assertArrayHasKey($resourceId, $result);
        $this->assertNotNull($result[$resourceId]);
        // The start time should be rounded/coincide with 12:00
        $this->assertEquals('12:00:00', $result[$resourceId]['start']->format('H:i:s'));
    }

    /**
     * ТЕСТ 2: The resource is completely free (no bookings).
     * The request is for early tomorrow morning (05:00).
     * The system should move the pointer to the beginning of the working day (08:00).
     */
    public function testFindNearestIntervalShiftsToWorkStartIfEarly(): void
    {
        $resourceId = Uuid::v7()->toString();
        $resource = $this->createTestResource($resourceId);

        $filters = new ResourceListFilterInput();
        $dateTime = (new \DateTimeImmutable('tomorrow 05:00:00', new \DateTimeZone('UTC')));
        $filters->startDate = $dateTime->format(\DateTimeInterface::ATOM);
        $filters->duration = 60;

        $this->bookingRepositoryStub->method('findBookingsForDate')->willReturn([]);

        $result = $this->service->findNearestIntervals([$resource], $filters);

        $this->assertArrayHasKey($resourceId, $result);
        $this->assertEquals('08:00:00', $result[$resourceId]['start']->format('H:i:s'));
    }

    /**
     * ТЕСТ 3: The resource is completely free (no bookings).
     * The request is for tomorrow evening (20:30).
     * An empty array should be returned, as it is no longer possible to book.
     */
    public function testFindNearestIntervalReturnsEmptyIfAfterWorkEnd(): void
    {
        $resourceId = Uuid::v7()->toString();
        $resource = $this->createTestResource($resourceId);

        $filters = new ResourceListFilterInput();
        $dateTime = (new \DateTimeImmutable('tomorrow 20:30:00', new \DateTimeZone('UTC')));
        $filters->startDate = $dateTime->format(\DateTimeInterface::ATOM);

        $result = $this->service->findNearestIntervals([$resource], $filters);

        $this->assertEmpty($result);
    }

    /**
     * TEST 4: Rounding check.
     * The time of 10:02 should be rounded up to 10:05 (a multiple of 5 minutes / 300 seconds).
     */
    public function testFindNearestIntervalRoundsMinutesUp(): void
    {
        $resourceId = Uuid::v7()->toString();
        $resource = $this->createTestResource($resourceId);

        $filters = new ResourceListFilterInput();
        $dateTime = (new \DateTimeImmutable('tomorrow 10:02:15', new \DateTimeZone('UTC'))); // 10:02
        $filters->startDate = $dateTime->format(\DateTimeInterface::ATOM);
        $filters->duration = 30;

        $this->bookingRepositoryStub->method('findBookingsForDate')->willReturn([]);

        $result = $this->service->findNearestIntervals([$resource], $filters);

        $this->assertEquals('10:05:00', $result[$resourceId]['start']->format('H:i:s'));
    }

    /**
     * TEST 5: Search for a slot "in the gap" between existing bookings.
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
        $baseDate = new \DateTimeImmutable('tomorrow 10:00:00', new \DateTimeZone('UTC'));

        $filters = new ResourceListFilterInput();
        $filters->startDate = $baseDate->format(\DateTimeInterface::ATOM);
        $filters->duration = 45;

        // Booking 1
        $booking1 = $this->createMockBooking(
            $baseDate->format(\DateTimeInterface::ATOM),
            $baseDate->modify('+1 hour')->format(\DateTimeInterface::ATOM)
        );
        // Booking 2
        $booking2 = $this->createMockBooking(
            $baseDate->modify('+2 hours')->format(\DateTimeInterface::ATOM),
            $baseDate->modify('+3 hours')->format(\DateTimeInterface::ATOM)
        );

        $this->bookingRepositoryStub->method('findBookingsForDate')
            ->willReturn([$booking1, $booking2]);

        $result = $this->service->findNearestIntervals([$resource], $filters);

        $this->assertNotNull($result[$resourceId]);
        // Start is expecting at 11:05 (End of Booking 1 + 5 minutes technical break)
        $this->assertEquals('11:05:00', $result[$resourceId]['start']->format('H:i:s'));
        $this->assertEquals('11:50:00', $result[$resourceId]['end']->format('H:i:s'));
    }

    /**
     * TEST 6: The slot does not fit into the gap due to a technical break.
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
        $baseDate = new \DateTimeImmutable('tomorrow 10:00:00', new \DateTimeZone('UTC'));

        $filters = new ResourceListFilterInput();
        $filters->startDate = $baseDate->format(\DateTimeInterface::ATOM);
        $filters->duration = 35;

        // Booking 1
        $booking1 = $this->createMockBooking(
            $baseDate->format(\DateTimeInterface::ATOM),
            $baseDate
                ->modify('+1 hour')
                ->format(\DateTimeInterface::ATOM)
        );
        // Booking 2
        $booking2 = $this->createMockBooking(
            $baseDate
                ->modify('+1 hour')
                ->modify('+40 minutes')
                ->format(\DateTimeInterface::ATOM),
            $baseDate
                ->modify('+2 hours')
                ->modify('+40 minutes')
                ->format(\DateTimeInterface::ATOM)
        );

        $this->bookingRepositoryStub->method('findBookingsForDate')
            ->willReturn([$booking1, $booking2]);

        $result = $this->service->findNearestIntervals([$resource], $filters);

        $slotAfterBooking2 = $baseDate
            ->modify('+2 hours')
            ->modify('+45 minutes')
            ->format(\DateTimeInterface::ATOM);

        // The gap between bookings has been missed. The slot must be assigned after booking a 2 + 5-minute break.
        $this->assertEquals($slotAfterBooking2, $result[$resourceId]['start']->format(\DateTimeInterface::ATOM));
    }
}
