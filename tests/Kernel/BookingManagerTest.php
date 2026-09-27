<?php

namespace App\Tests\Kernel;

use App\Entity\Booking;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\ResourceType;
use App\Service\BookingManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class BookingManagerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BookingManager $bookingManager;

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
        $end1 = $baseDate->setTime(14, 0, 0);

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

                $newBooking = $this->bookingManager->createBooking($freshUserForManager, $freshResourceForManager, $start2, $end2);

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
}
