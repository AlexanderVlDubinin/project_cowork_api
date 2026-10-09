<?php

namespace App\Tests\Integration\Controller;

use App\Entity\Booking;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\ResourceType;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class BookingControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Resource $testResource;
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
        // Clearing old users with the same email address
        $existingUser = $this->em->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);
        if ($existingUser) {
            $this->em->remove($existingUser);
        }
        $existingUser = $this->em->getRepository(User::class)->findOneBy(['email' => 'client@example.com']);
        if ($existingUser) {
            $this->em->remove($existingUser);
        }
        // Cleaning up old test resources to avoid overgrowth of the database
        $existingResources = $this->em->getRepository(Resource::class)->findBy(['title' => 'Test Desk № 1']);
        foreach ($existingResources as $res) {
            $this->em->remove($res);
        }
        $this->em->flush();

        // test admin
        $user = new User();
        $user->setFullName('Test Admin 1');
        $user->setEmail('admin@example.com');
        //$user->setPassword('password123'); // use PasswordHasher
        $hashedPassword = $this->passwordHasher->hashPassword($user, 'password123');
        $user->setPassword($hashedPassword);
        $user->setRoles(['ROLE_ADMIN']);
        $this->em->persist($user);

        // test user
        $user = new User();
        $user->setFullName('Test User 1');
        $user->setEmail('client@example.com');
        //$user->setPassword('password123'); // use PasswordHasher
        $hashedPassword = $this->passwordHasher->hashPassword($user, 'password123');
        $user->setPassword($hashedPassword);
        $user->setRoles(['ROLE_USER']);
        $this->em->persist($user);

        // test resource
        $this->testResource = new Resource();
        $this->testResource->setTitle('Test Desk № 1');
        $this->testResource->setType(ResourceType::DESK);
        $this->testResource->setDescription('Test Desk № 1 Description');
        $this->testResource->setIsActive(true);
        $this->testResource->setPricePerHour(500);
        $this->em->persist($this->testResource);

        $this->em->flush();
    }

    private function logInAsClientOrAdmin($isAdmin = false): void
    {
        $userRepository = $this->em->getRepository(User::class);
        if ($isAdmin) {
            $testUser = $userRepository->findOneBy(['email' => 'admin@example.com']);
        } else {
            $testUser = $userRepository->findOneBy(['email' => 'client@example.com']);
        }

        // When not using JWT
        // Not suitable for LexikJWTAuthenticationBundle and JWT !!!
        // The $client->LoginUser() method is designed so that it writes
        // a security token to the client's session (cookies).
        // However, the api firewall (for Lexik) completely ignores sessions (stateless: true),
        // which is why any second request returns a 401 Unauthorized error.
        //$this->client->loginUser($testUser);

        // When using JWT
        // Getting the JWT manager from the Symfony container
        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);
        // Generating a token for user
        $this->jwtToken = $jwtManager->create($testUser);
    }

    private function getAuthHeaders(): array  // JWT
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtToken,
        ];
    }

    /**
     * Test getting bookings list success
     */
    public function testGettingBookingsListSuccess(): void
    {
        $this->logInAsClientOrAdmin();

        $this->client->request(
            'GET',
            '/api/bookings',
            [],
            [],
            // 'CONTENT_TYPE' => 'application/json', // no JWT
            $this->getAuthHeaders(), // JWT
        );

        $this->assertResponseIsSuccessful();
    }

    /**
     * Test getting bookings list with wrong parameter returns 422 error
     */
    public function testGettingBookingsListWithWrongParameter(): void
    {
        $this->logInAsClientOrAdmin(true);

        // not ATOM format (ISO 8601)
        $wrongStartDate = (new \DateTimeImmutable('first monday of next year'))
            ->format('Y-m-d');
        $this->client->request(
            'GET',
            '/api/bookings',
            [
                'startDate' => $wrongStartDate,
            ],
            [],
            // 'CONTENT_TYPE' => 'application/json', // no JWT
            $this->getAuthHeaders(), // JWT
        );

        // DTO validation error
        $this->assertEquals(422, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $responseData);
        $this->assertArrayHasKey('startDate', $responseData['errors']);
        $this->assertStringContainsString(
            'Start date must be in ATOM format (ISO 8601)',
            $responseData['errors']['startDate']
        );
    }

    /**
     * Test booking creation success and list check (1 booking created)
     */
    public function testBookingCreationSuccessAndListCheck(): void
    {
        $this->logInAsClientOrAdmin();

        $startDate = (new \DateTimeImmutable('next week monday'))
            ->setTime(12, 0, 0)
            ->format('Y-m-d\TH:i:s\Z');
        $this->client->request(
            'POST',
            '/api/booking',
            [],
            [],
            // 'CONTENT_TYPE' => 'application/json', // no JWT
            $this->getAuthHeaders(), // JWT
            json_encode([
                'resourceId' => $this->testResource->getId()->toString(),
                'startedAt' => $startDate,
                'duration' => 120 // duration in minutes (for example, 2 hours)
            ])
        );

        $this->assertEquals(201, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $responseData);
        $this->assertArrayHasKey('resourceId', $responseData);
        $this->assertStringContainsString(
            $this->testResource->getId()->toString(),
            $responseData['resourceId']
        );
        $this->assertEquals('pending', $responseData['status']);

        $this->client->request(
            'GET',
            '/api/bookings',
            [],
            [],
            // 'CONTENT_TYPE' => 'application/json', // no JWT
            $this->getAuthHeaders(), // JWT
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $responseData);
        $this->assertCount(1, $responseData['data']);
    }

    /**
     * 409 STATUS TEST (Overbooking)
     * Trying to book the same place for the same time interval twice
     */
    public function testCollisionReturns409Conflict(): void
    {
        $this->logInAsClientOrAdmin();

        $startDate = (new \DateTimeImmutable('next week monday'))
            ->setTime(14, 0, 0)
            ->format('Y-m-d\TH:i:s\Z');
        $payload = [
            'resourceId' => $this->testResource->getId()->toString(),
            'startedAt' => $startDate,
            'duration' => 60
        ];

        // 1. The first successful booking
        $this->client->request(
            'POST',
            '/api/booking',
            [],
            [],
            // 'CONTENT_TYPE' => 'application/json', // no JWT
            $this->getAuthHeaders(), // JWT
            json_encode($payload)
        );
        $this->assertEquals(201, $this->client->getResponse()->getStatusCode());

        $this->logInAsClientOrAdmin();
        // 2. A second attempt at the same time
        $this->client->request(
            'POST',
            '/api/booking',
            [],
            [],
            // 'CONTENT_TYPE' => 'application/json', // no JWT
            $this->getAuthHeaders(), // JWT
            json_encode($payload)
        );

        // there should be a 409 HTTP_CONFLICT status
        $this->assertEquals(409, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $responseData);
        $this->assertArrayHasKey('error', $responseData['errors']);
        $this->assertStringContainsString(
            'This time interval is already occupied for the selected resource.',
            $responseData['errors']['error']
        );
    }

    /**
     * Test trying to create booking in past returns validation error
     */
    public function testBookingInPastReturnsValidationError(): void
    {
        $this->logInAsClientOrAdmin();

        $startDateInPast = (new \DateTimeImmutable('first monday of last year'))
            ->setTime(12, 0, 0)
            ->format('Y-m-d\TH:i:s\Z');
        $this->client->request(
            'POST',
            '/api/booking',
            [],
            [],
            // 'CONTENT_TYPE' => 'application/json', // no JWT
            $this->getAuthHeaders(), // JWT
            json_encode([
                'resourceId' => $this->testResource->getId()->toString(),
                'startedAt' => $startDateInPast, // attempted booking in the past
                'duration' => 60
            ])
        );

        // DTO validation error
        $this->assertEquals(422, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $responseData);
        $this->assertArrayHasKey('startedAt', $responseData['errors']);
        $this->assertStringContainsString(
            'You cannot book a resource in the past.',
            $responseData['errors']['startedAt']
        );
    }

    /**
     * Test cancel booking success and repeat cancel returns validation error
     */
    public function testCancelBookingSuccessAndRepeatCancel(): void
    {
        $this->logInAsClientOrAdmin();

        $startDate = (new \DateTimeImmutable('next week monday'))
            ->setTime(12, 0, 0)
            ->format('Y-m-d\TH:i:s\Z');
        $this->client->request(
            'POST',
            '/api/booking',
            [],
            [],
            // 'CONTENT_TYPE' => 'application/json', // no JWT
            $this->getAuthHeaders(), // JWT
            json_encode([
                'resourceId' => $this->testResource->getId()->toString(),
                'startedAt' => $startDate,
                'duration' => 120 // duration in minutes (for example, 2 hours)
            ])
        );

        $this->assertEquals(201, $this->client->getResponse()->getStatusCode());

        $userRepository = $this->em->getRepository(User::class);
        $testUser = $userRepository->findOneBy(['email' => 'client@example.com']);

        $bookingRepository = $this->em->getRepository(Booking::class);
        $testBooking = $bookingRepository->findOneBy(['user' => $testUser]);
        $this->assertEquals($this->testResource->getId()->toString(), $testBooking->getResource()->getId()->toString());

        $this->client->request(
            'DELETE',
            '/api/booking/'.$testBooking->getId()->toString(),
            [],
            [],
            // 'CONTENT_TYPE' => 'application/json', // no JWT
            $this->getAuthHeaders(), // JWT
        );

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());
        $testBooking = $bookingRepository->findOneBy(['user' => $testUser]);
        $this->assertEquals('cancelled', $testBooking->getStatus()->value);

        $this->client->request(
            'DELETE',
            '/api/booking/'.$testBooking->getId()->toString(),
            [],
            [],
            // 'CONTENT_TYPE' => 'application/json', // no JWT
            $this->getAuthHeaders(), // JWT
        );

        $this->assertEquals(400, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $responseData);
        $this->assertArrayHasKey('error', $responseData['errors']);
        $this->assertStringContainsString(
            'This booking is already cancelled.',
            $responseData['errors']['error']
        );
    }

    /**
     * Test cancel booking by wrong user returns 403 error
     */
    public function testCancelBookingByWrongUser(): void
    {
        $this->logInAsClientOrAdmin(true);

        $startDate = (new \DateTimeImmutable('next week monday'))
            ->setTime(12, 0, 0)
            ->format('Y-m-d\TH:i:s\Z');
        $this->client->request(
            'POST',
            '/api/booking',
            [],
            [],
            // 'CONTENT_TYPE' => 'application/json', // no JWT
            $this->getAuthHeaders(), // JWT
            json_encode([
                'resourceId' => $this->testResource->getId()->toString(),
                'startedAt' => $startDate,
                'duration' => 120 // duration in minutes (for example, 2 hours)
            ])
        );
        $this->assertEquals(201, $this->client->getResponse()->getStatusCode());

        $this->logInAsClientOrAdmin();

        $userRepository = $this->em->getRepository(User::class);
        $testAdmin = $userRepository->findOneBy(['email' => 'admin@example.com']);

        $bookingRepository = $this->em->getRepository(Booking::class);
        $testBooking = $bookingRepository->findOneBy(['user' => $testAdmin]);

        $this->client->request(
            'DELETE',
            '/api/booking/'.$testBooking->getId()->toString(),
            [],
            [],
            // 'CONTENT_TYPE' => 'application/json', // no JWT
            $this->getAuthHeaders(), // JWT
        );
        $this->assertEquals(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Test booking creation carries over night successfully
     * and returns correct calculations of time and price
     */
    public function testBookingCreationCarriesOverNightAndReturnsCorrectCalculations(): void
    {
        $this->logInAsClientOrAdmin();

        // Wednesday 19:00:00. The coworking space will close in 1 hour (at 20:00)
        // Booking order - duration = 120 minutes (2 hours).
        // It is expected that 1 hour will be scheduled for Wednesday,
        // and the remaining 1 hour will be postponed to Thursday from 08:00 to 09:00
        $startDate = (new \DateTimeImmutable('next week wednesday'))
            ->setTime(19, 0, 0);
        $endDate = $startDate->modify('+1 day');

        $this->client->request(
            'POST',
            '/api/booking',
            [],
            [],
            $this->getAuthHeaders(),
            json_encode([
                'resourceId' => $this->testResource->getId()->toString(),
                'startedAt' => $startDate->format('Y-m-d\TH:i:s\Z'),
                'endDate' => $endDate->format('Y-m-d\TH:i:s\Z'),
                'duration' => 120
            ])
        );

        $this->assertEquals(201, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        // Checking end-to-end calculations in the API response
        $this->assertArrayHasKey('endedAt', $responseData);
        $expectedEndDay = (new \DateTimeImmutable('next week thursday'))
            ->setTime(9, 0, 0)->format('Y-m-d\TH:i:s');
        $this->assertStringContainsString($expectedEndDay, $responseData['endedAt']);

        $this->assertEquals(120, $responseData['totalTime']); // Net 120 working minutes
        $this->assertEquals(1000, $responseData['totalPrice']); // 2 working hours * 500 per hour = 1000
    }

    /**
     * Test booking entirely in weekend returns validation error 422
     */
    public function testBookingEntirelyInWeekendReturnsValidationError422(): void
    {
        $this->logInAsClientOrAdmin();

        // Attempt to book time on Sunday (coworking is closed)
        $sundayStart = (new \DateTimeImmutable('next week sunday'))
            ->setTime(12, 0, 0)
            ->format('Y-m-d\TH:i:s\Z');
        $sundayEnd = (new \DateTimeImmutable('next week sunday'))
            ->setTime(15, 0, 0)
            ->format('Y-m-d\TH:i:s\Z');

        $this->client->request(
            'POST',
            '/api/booking',
            [],
            [],
            $this->getAuthHeaders(),
            json_encode([
                'resourceId' => $this->testResource->getId()->toString(),
                'startedAt' => $sundayStart,
                'endDate' => $sundayEnd
            ])
        );

        $this->assertEquals(422, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $responseData);
        $this->assertArrayHasKey('startedAt', $responseData['errors']);
        $this->assertStringContainsString(
            'The requested interval is entirely outside of working hours (nights or weekends).',
            $responseData['errors']['startedAt']
        );
    }
}
