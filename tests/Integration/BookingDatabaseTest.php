<?php

namespace App\Tests\Integration;

use App\Entity\Booking;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\ResourceType;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class BookingDatabaseTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        // Cleaning/preparing the environment before each test
        $this->createTestData();
    }

    private function createTestData(): void
    {
        // DB clear
        // Clearing old users to avoid overgrowth of the database
        $existingUsers = $this->em->getRepository(User::class)->findAll();
        foreach ($existingUsers as $existingUser) {
            $this->em->remove($existingUser);
        }
        // Cleaning up old test resources to avoid overgrowth of the database
        $existingResources = $this->em->getRepository(Resource::class)->findAll();
        foreach ($existingResources as $res) {
            $this->em->remove($res);
        }
        $this->em->flush();

        // test user
        $user = new User();
        $user->setFullName('Test User 1');
        $user->setEmail('client@example.com');
        $hashedPassword = $this->passwordHasher->hashPassword($user, 'password123');
        $user->setPassword($hashedPassword);
        $user->setRoles(['ROLE_USER']);
        $this->em->persist($user);

        $user = new User();
        $user->setFullName('Test User 2');
        $user->setEmail('client2@example.com');
        $hashedPassword = $this->passwordHasher->hashPassword($user, 'password123');
        $user->setPassword($hashedPassword);
        $user->setRoles(['ROLE_USER']);
        $this->em->persist($user);

        for ($i = 1; $i <= 12; $i++) {
            // test resource
            $testResource = new Resource();
            $testResource->setTitle('Test Desk № '.$i);
            $testResource->setType(ResourceType::DESK);
            $testResource->setDescription('Test Desk № '.$i.' Description');
            $testResource->setIsActive(true);
            $testResource->setPricePerHour(500);
            $this->em->persist($testResource);
        }

        $this->em->flush();
    }

    /**
     * Test that the database exclusion constraint prevents overlapping bookings
     */
    public function testDbExclusionConstraintPreventsOverlapping(): void
    {
        $userRepository = $this->em->getRepository(User::class);
        $testUser = $userRepository->findOneBy(['email' => 'client@example.com']);
        $testUser2 = $userRepository->findOneBy(['email' => 'client2@example.com']);

        $resource = $this->em->getRepository(Resource::class)->findOneBy([]);

        $createDate = (new \DateTimeImmutable('+1 month'))
            ->modify('weekday')
            ->setTime(10, 0, 0)
            ->format('Y-m-d\TH:i:s\Z');
        $startDate1 = (new \DateTimeImmutable('+1 month'))
            ->modify('weekday')
            ->setTime(12, 0, 0)
            ->format('Y-m-d\TH:i:s\Z');
        $endDate1 = (new \DateTimeImmutable($startDate1))
            ->modify('+2 hours')
            ->format('Y-m-d\TH:i:s\Z');

        $booking1 = new Booking();
        $booking1->setResource($resource)
            ->setStartedAt(new \DateTimeImmutable($startDate1))
            ->setEndedAt(new \DateTimeImmutable($endDate1))
            ->setStatus(BookingStatus::CONFIRMED)
            ->setTotalPrice(1000)
            ->setCreatedAt(new \DateTimeImmutable($createDate))
            ->setUser($testUser);

        $startDate2 = (new \DateTimeImmutable('+1 month'))
            ->modify('weekday')
            ->setTime(13, 0, 0)
            ->format('Y-m-d\TH:i:s\Z');
        $endDate2 = (new \DateTimeImmutable($startDate2))
            ->modify('+2 hours')
            ->format('Y-m-d\TH:i:s\Z');

        $booking2 = new Booking();
        $booking2->setResource($resource)
            ->setStartedAt(new \DateTimeImmutable($startDate2)) // Overlaps the first one!
            ->setEndedAt(new \DateTimeImmutable($endDate2))
            ->setStatus(BookingStatus::PENDING)
            ->setTotalPrice(1000)
            ->setCreatedAt(new \DateTimeImmutable($createDate))
            ->setUser($testUser2);

        $this->em->persist($booking1);
        $this->em->persist($booking2);

        // The database is expected to throw a uniqueness violation exception.
        $this->expectException(DriverException::class);

        $this->em->flush();
    }

    /**
     * Test that a new booking is successfully created when it overlaps with INACTIVE bookings
     */
    public function testDbExclusionConstraintAllowsOverlappingWithInactiveStatuses(): void
    {
        $userRepository = $this->em->getRepository(User::class);
        $testUser = $userRepository->findOneBy(['email' => 'client@example.com']);
        $testUser2 = $userRepository->findOneBy(['email' => 'client2@example.com']);

        $resource = $this->em->getRepository(Resource::class)->findOneBy([]);

        // Sets of inactive statuses to check
        $inactiveStatuses = [
            BookingStatus::CANCELLED,
            BookingStatus::EXPIRED,
            BookingStatus::FAILED,
        ];

        $createDate = (new \DateTimeImmutable('+1 month'))
            ->modify('weekday')
            ->setTime(10, 0, 0)
            ->format('Y-m-d\TH:i:s\Z');

        $startDate1 = (new \DateTimeImmutable('+1 month'))
            ->modify('weekday')
            ->setTime(12, 0, 0)
            ->format('Y-m-d\TH:i:s\Z');

        $endDate1 = (new \DateTimeImmutable($startDate1))
            ->modify('+2 hours')
            ->format('Y-m-d\TH:i:s\Z');

        $startDate2 = (new \DateTimeImmutable('+1 month'))
            ->modify('weekday')
            ->setTime(13, 0, 0) // Overlaps the first interval!
            ->format('Y-m-d\TH:i:s\Z');

        $endDate2 = (new \DateTimeImmutable($startDate2))
            ->modify('+2 hours')
            ->format('Y-m-d\TH:i:s\Z');

        foreach ($inactiveStatuses as $status) {
            // 1. Creating the first booking with an INACTIVE status
            $booking1 = new Booking();
            $booking1->setResource($resource)
                ->setStartedAt(new \DateTimeImmutable($startDate1))
                ->setEndedAt(new \DateTimeImmutable($endDate1))
                ->setStatus($status) // <--- Подставляем CANCELLED, EXPIRED, FAILED
                ->setTotalPrice(1000)
                ->setCreatedAt(new \DateTimeImmutable($createDate))
                ->setUser($testUser);

            // 2. Creating a second booking that overlaps with the first one
            $booking2 = new Booking();
            $booking2->setResource($resource)
                ->setStartedAt(new \DateTimeImmutable($startDate2))
                ->setEndedAt(new \DateTimeImmutable($endDate2))
                ->setStatus(BookingStatus::CONFIRMED)
                ->setTotalPrice(1000)
                ->setCreatedAt(new \DateTimeImmutable($createDate))
                ->setUser($testUser2);

            $this->em->persist($booking1);
            $this->em->persist($booking2);

            // 3. Performing a flush. The database should NOT throw an exception,
            // as the conditional GiST index ignores inactive statuses.
            try {
                $this->em->flush();
                $this->assertTrue(true, 'Flush completed successfully for the status ' . $status->value);
            } catch (\Exception $e) {
                $this->fail(sprintf(
                    'The index mistakenly blocked the overlay for the inactive status "%s". Error: %s',
                    $status->value,
                    $e->getMessage()
                ));
            }

            // 4. Clearing the database/EntityManager before the next iteration of the loop
            // so that the records do not interfere with each other.
            $this->em->remove($booking1);
            $this->em->remove($booking2);
            $this->em->flush();
        }
    }
}
