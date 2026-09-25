<?php

namespace App\Tests\Integration\Controller;

use App\Entity\Booking;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\ResourceType;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ResourceClientControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $passwordHasher;
    private string $jwtToken;

    protected function setUp(): void
    {
        $this->client = static::createClient();
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

    private function logInAsClient(): void
    {
        $userRepository = $this->em->getRepository(User::class);
        $testUser = $userRepository->findOneBy(['email' => 'client@example.com']);

        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);
        $this->jwtToken = $jwtManager->create($testUser);
    }

    private function getAuthHeaders(): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtToken,
        ];
    }

    /**
     * Test getting resources list success
     */
    public function testGettingResourcesListSuccess(): void
    {
        $this->logInAsClient();

        $this->client->request(
            'GET',
            '/api/resources',
            [],
            [],
            $this->getAuthHeaders(),
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $responseData);
        $this->assertCount(10, $responseData['data']);

        $resourceRepository = $this->em->getRepository(Resource::class);
        $testResources = $resourceRepository->findAll();

        $this->assertCount(12, $testResources);
    }

    /**
     * Test getting bookings list with wrong parameter returns 422
     */
    public function testGettingBookingsListWithWrongParameter(): void
    {
        $this->logInAsClient();

        $this->client->request(
            'GET',
            '/api/resources',
            [
                'type' => 'desk1',
            ],
            [],
            $this->getAuthHeaders(), // JWT
        );

        // DTO validation error
        $this->assertEquals(422, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $responseData);
        $this->assertArrayHasKey('type', $responseData['errors']);
        $this->assertStringContainsString(
            'Invalid resource type. Available options: desk, meeting_room',
            $responseData['errors']['type']
        );
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
            ->setStatus(BookingStatus::PENDING)
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
            ->setStatus(BookingStatus::CONFIRMED)
            ->setTotalPrice(1000)
            ->setCreatedAt(new \DateTimeImmutable($createDate))
            ->setUser($testUser2);

        $this->em->persist($booking1);
        $this->em->persist($booking2);

        // The database is expected to throw a uniqueness violation exception.
        $this->expectException(DriverException::class);

        $this->em->flush();
    }
}
