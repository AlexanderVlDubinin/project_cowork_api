<?php

namespace App\Tests\Integration\Controller;

use App\Entity\Booking;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\ResourceType;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ResourceUsageControllerTest extends WebTestCase
{
    use ClockSensitiveTrait; // trait is used for flexible time simulation (mockTime())
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Resource $testResource;
    private User $testUser;
    private UserPasswordHasherInterface $passwordHasher;
    private \DateTimeImmutable $baseTime;
    private string $jwtToken;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        // Fixing the "now" time for Monday of the following week at 12:00 p.m.
        $this->baseTime = new \DateTimeImmutable('next week monday 12:00:00', new \DateTimeZone('UTC'));
        self::mockTime($this->baseTime);

        // Cleaning/preparing the environment before each test
        $this->createTestData();
    }

    private function createTestData(): void
    {
        // DB clear
        // Clearing old users with the same email address
        $existingUsers = $this->em->getRepository(User::class)->findAll();
        foreach ($existingUsers as $existingUser) {
            $this->em->remove($existingUser);
        }
        // Cleaning up old test resources to avoid overgrowth of the database
        $existingResources = $this->em->getRepository(Resource::class)->findAll();
        foreach ($existingResources as $res) {
            $this->em->remove($res);
        }
        // Clearing old bookings
        $existingBookings = $this->em->getRepository(Booking::class)->findAll();
        foreach ($existingBookings as $booking) {
            $this->em->remove($booking);
        }
        $this->em->flush();

        // test user
        $this->testUser = new User();
        $this->testUser->setFullName('Test User 1');
        $this->testUser->setEmail('client@example.com');
        //$user->setPassword('password123'); // use PasswordHasher
        $hashedPassword = $this->passwordHasher->hashPassword($this->testUser, 'password123');
        $this->testUser->setPassword($hashedPassword);
        $this->testUser->setRoles(['ROLE_USER']);
        $this->em->persist($this->testUser);

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
     * 1. It is not possible to make a CHECK_IN if the booking is not in the CONFIRMED status.
     */
    public function testCheckInFailsIfStatusIsNotConfirmed(): void
    {
        $this->logInAsClient();

        // 1. Creating a test booking with the PENDING status
        $booking = new Booking();
        $booking
            ->setUser($this->testUser)
            ->setResource($this->testResource)
            ->setStatus(BookingStatus::PENDING)
            ->setStartedAt($this->baseTime->modify('+1 hour'))
            ->setEndedAt($this->baseTime->modify('+2 hours'))
            ->setTotalPrice(1000);

        $this->em->persist($booking);
        $this->em->flush();


        // 2. Making a request to the endpoint
        $this->client->request(
            'POST',
            sprintf('/api/booking/%s/check_in', $booking->getId()),
            [], [],
            $this->getAuthHeaders(),
        );

        // 3. Checking the response
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString(
            'Check-in is not possible. The booking must be confirmed (paid).',
            $responseData['error']
        );
    }

    /**
     * 2. It is impossible to make a CHECK_IN if to arrive too early.
     */
    public function testCheckInFailsIfItIsTooEarly(): void
    {
        $this->logInAsClient();

        // 1. Set the system clock to baseTime+1 hour
        self::mockTime($this->baseTime->modify('+1 hour'));

        // 2. Creating a booking that will start much later (2 hours after the base time)
        // Since the bookingTechBreak buffer (during which check_in occurs) is 5 minutes,
        // an attempt to check_in in an hour should return an error.
        $booking = new Booking();
        $booking
            ->setUser($this->testUser)
            ->setResource($this->testResource)
            ->setStatus(BookingStatus::CONFIRMED)
            ->setStartedAt($this->baseTime->modify('+2 hour')) // baseTime+2 hour
            ->setEndedAt($this->baseTime->modify('+3 hour')) // baseTime+3 hour
            ->setTotalPrice(1500);

        $this->em->persist($booking);
        $this->em->flush();

        // 3. Making a request
        $this->client->request(
            'POST',
            sprintf('/api/booking/%s/check_in', $booking->getId()),
            [], [],
            $this->getAuthHeaders(),
        );

        // 4. Checking the response
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString(
            'It is too early for a check-in.',
            $responseData['error']
        );
    }

    /**
     * 3. Successfully made CHECK_IN (time within the technical buffer or during booking)
     */
    public function testCheckInSuccessWhenTimeIsValid(): void
    {
        $this->logInAsClient();

        // 1. Set the system clock to baseTime+57 minutes
        self::mockTime($this->baseTime->modify('+57 minutes'));

        // 2. Creating a booking that will start just a bit later (1 hour after the base time)
        // Since the bookingTechBreak buffer (during which check_in occurs) is 5 minutes,
        // an attempt to check_in in 57 minutes should be successful.
        $booking = new Booking();
        $booking
            ->setUser($this->testUser)
            ->setResource($this->testResource)
            ->setStatus(BookingStatus::CONFIRMED)
            ->setStartedAt($this->baseTime->modify('+1 hour')) // baseTime+1 hour
            ->setEndedAt($this->baseTime->modify('+2 hour')) // baseTime+2 hour
            ->setTotalPrice(1500);

        $this->em->persist($booking);
        $this->em->flush();

        // 3. Making a request
        $this->client->request(
            'POST',
            sprintf('/api/booking/%s/check_in', $booking->getId()),
            [], [],
            $this->getAuthHeaders(),
        );

        // 4. Checking the response
        $this->assertResponseIsSuccessful();
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertArrayHasKey('status', $responseData);
        $this->assertStringContainsString(
            'You have successfully started using the resource.',
            $responseData['message']
        );
        $this->assertStringContainsString(
            BookingStatus::CHECKED_IN->value,
            $responseData['status']
        );

        // Checking that the changes are physically saved in the database.
        $this->em->refresh($booking);
        $this->assertSame(BookingStatus::CHECKED_IN, $booking->getStatus());
    }
}
