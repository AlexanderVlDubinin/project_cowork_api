<?php

namespace App\Tests\Kernel\Service;

use App\DTO\BookingInput;
use App\Entity\Booking;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\ResourceType;
use App\Service\BookingManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BookingManagerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BookingManager $bookingManager;
    private int $bookingTechBreak = 5; // A stub for tech break

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->bookingManager = $container->get(BookingManager::class);

        $this->clearDatabase();
    }

    private function clearDatabase(): void
    {
        // Clearing the tables before the test
        foreach ($this->em->getRepository(Booking::class)->findAll() as $b) {
            $this->em->remove($b);
        }
        foreach ($this->em->getRepository(Resource::class)->findAll() as $r) {
            $this->em->remove($r);
        }
        foreach ($this->em->getRepository(User::class)->findAll() as $u) {
            $this->em->remove($u);
        }
        $this->em->flush();
    }

    /**
     * HELPER: Adjusts the date to account for the booking technical break
     *
     * @param \DateTimeImmutable $date
     * @param string $dateType start or end
     * @return \DateTimeImmutable
     */
    private function getDbDate(\DateTimeImmutable $date, string $dateType): \DateTimeImmutable
    {
        $dateDb = $date;
        if ($dateType === 'start') {
            if ($date->format('H:i') !== '08:00') {
                $dateDb = $date->modify("-{$this->bookingTechBreak} minutes");
            }
        }
        if ($dateType === 'end') {
            if ($date->format('H:i') !== '20:00') {
                $dateDb = $date->modify("+{$this->bookingTechBreak} minutes");
            }
        }

        return $dateDb;
    }

    /**
     * Test that BookingManager ignores inactive bookings during LEVEL 1 validation
     */
    public function testBookingManagerAllowsOverlappingWithInactiveStatuses(): void
    {
        // 1. Creating the initial test entities
        $user = new User();
        $user->setFullName('Manager Test User')
            ->setEmail('manager_test@example.com')
            ->setPassword('password123');
        $this->em->persist($user);

        $resource = new Resource();
        $resource->setTitle('Premium Desk')
            ->setType(ResourceType::DESK)
            ->setDescription('Test Description')
            ->setIsActive(true)
            ->setPricePerHour(1000);
        $this->em->persist($resource);

        $this->em->flush();
        $this->em->clear(); // Immediately disconnect these initial test entities so that all control goes through fresh proxies

        // 2. List of inactive statuses for alternate verification
        $inactiveStatuses = [
            BookingStatus::CANCELLED,
            BookingStatus::EXPIRED,
            BookingStatus::FAILED,
        ];

        // Defining the basic time intervals (on a weekday, within business hours)
        $baseDate = (new \DateTimeImmutable('+1 week'))->modify('next monday');
        $start1 = $baseDate->setTime(12, 0, 0);
        $startDb1 = $this->getDbDate($start1, 'start');
        $end1 = $baseDate->setTime(14, 0, 0);
        $endDb1 = $this->getDbDate($end1, 'end');

        $start2 = $baseDate->setTime(13, 0, 0); // Overlaps the first one!
        $end2 = $baseDate->setTime(15, 0, 0);

        foreach ($inactiveStatuses as $status) {
            // IMPORTANT: getting "fresh" objects from the database at EACH iteration of the cycle
            $freshUser = $this->em->getRepository(User::class)->findOneBy(['email' => 'manager_test@example.com']);
            $freshResource = $this->em->getRepository(Resource::class)->findOneBy([]);

            // 3. Creating an old booking directly through EntityManager with inactive status
            $oldBooking = new Booking();
            $oldBooking->setUser($freshUser) // Using freshUser
                ->setResource($freshResource) // Using freshResource
                ->setStartedAt($start1)
                ->setEndedAt($end1)
                ->setDbBlockedFrom($startDb1)
                ->setDbBlockedTo($endDb1)
                ->setStatus($status)
                ->setTotalPrice(2000)
                ->setCreatedAt(new \DateTimeImmutable());

            $this->em->persist($oldBooking);
            $this->em->flush();
            $this->em->clear(); // Clearing the Doctrine cache to simulate a clean DATABASE query

            // 4. Trying to create a new overlapping booking through the BookingManager
            try {
                // Recreating proxy objects to call BookingManager after clearing the EM
                $freshUserForManager = $this->em->getRepository(User::class)->findOneBy(['email' => 'manager_test@example.com']);
                $freshResourceForManager = $this->em->getRepository(Resource::class)->findOneBy([]);

                // Filling the DTO
                $bookingInput = new BookingInput();
                $bookingInput->resourceId = $freshResourceForManager->getId()->toString();
                $bookingInput->startedAt = $start2->format(\DateTimeInterface::ATOM);
                $bookingInput->endDate = $end2->format(\DateTimeInterface::ATOM);
                $bookingInput->duration = null;

                $newBooking = $this->bookingManager->createBookingFromParams($freshUserForManager, $bookingInput, $start2);

                // Checking that the booking has actually been created and saved.
                $this->assertInstanceOf(Booking::class, $newBooking);
                $this->assertNotNull($newBooking->getId());
                $this->assertEquals(BookingStatus::PENDING, $newBooking->getStatus());
            } catch (\Exception $e) {
                $this->fail(sprintf(
                    'BookingManager has blocked the booking at the business logic level for the inactive status "%s". Error: %s',
                    $status->name,
                    $e->getMessage()
                ));
            }

            // 5. Clearing the database/EntityManager before the next iteration of the loop
            // so that the records do not interfere with each other.
            foreach ($this->em->getRepository(Booking::class)->findAll() as $b) {
                $this->em->remove($b);
            }
            $this->em->flush();
            $this->em->clear(); // Be sure to clean it up at the end of the iteration!
        }
    }

    /**
     * Checking the end-to-end technical break.
     * The new booking is started 2 minutes after the old one,
     * but the technical break of the coworking (5 minutes) should block this.
     */
    public function testBookingManagerEnforcesTechBreakIntersectionInDatabase(): void
    {
        $user = new User();
        $user->setFullName('Tech Break User')
            ->setEmail('tech_user@example.com')
            ->setPassword('password123');
        $this->em->persist($user);

        $resource = new Resource();
        $resource->setTitle('Standard Desk')
            ->setType(ResourceType::DESK)
            ->setIsActive(true)
            ->setPricePerHour(500);
        $this->em->persist($resource);
        $this->em->flush();

        $baseDate = (new \DateTimeImmutable('+1 week'))->modify('next tuesday');

        // First booking: 10:00 - 12:00. Due to the technical break (+5 min), the resource is occupied until 12:05
        $start1 = $baseDate->setTime(10, 0, 0);
        $startDb1 = $this->getDbDate($start1, 'start');
        $end1 = $baseDate->setTime(12, 0, 0);
        $endDb1 = $this->getDbDate($end1, 'end');

        $existingBooking = new Booking();
        $existingBooking->setUser($user)
            ->setResource($resource)
            ->setStartedAt($start1)
            ->setEndedAt($end1)
            ->setDbBlockedFrom($startDb1)
            ->setDbBlockedTo($endDb1)
            ->setStatus(BookingStatus::CONFIRMED)
            ->setTotalPrice(1000)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($existingBooking);
        $this->em->flush();
        $this->em->clear(); // Clearing the Doctrine cache to simulate a clean DATABASE query

        // The second booking is trying to start at 12:02 (inside the window of the technical break 12:00 - 12:05)
        $start2 = $baseDate->setTime(12, 2, 0);
        $end2 = $baseDate->setTime(14, 0, 0);

        $freshUser = $this->em->getRepository(User::class)->findOneBy(['email' => 'tech_user@example.com']);
        $freshResource = $this->em->getRepository(Resource::class)->findOneBy([]);

        $bookingInput = new BookingInput();
        $bookingInput->resourceId = $freshResource->getId()->toString();
        $bookingInput->startedAt = $start2->format(\DateTimeInterface::ATOM);
        $bookingInput->endDate = $end2->format(\DateTimeInterface::ATOM);
        $bookingInput->duration = null;

        // Expecting the exclusion of overlapping business logic intervals.
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('This time interval is already occupied for the selected resource.');

        $this->bookingManager->createBookingFromParams($freshUser, $bookingInput, $start2);
    }
}
