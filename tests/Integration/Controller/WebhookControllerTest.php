<?php

namespace App\Tests\Integration\Controller;

use App\Entity\Booking;
use App\Entity\PaymentTransaction;
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

class WebhookControllerTest extends WebTestCase
{
    use ClockSensitiveTrait; // trait is used for flexible time simulation (mockTime())

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $testUser;
    private Resource $testResource;
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
        // Clearing old payment transactions
        $existingTransactions = $this->em->getRepository(PaymentTransaction::class)->findAll();
        foreach ($existingTransactions as $transaction) {
            $this->em->remove($transaction);
        }
        $this->em->flush();

        // test user
        $this->testUser = new User();
        $this->testUser->setFullName('Test User 1');
        $this->testUser->setEmail('client@example.com');
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
     * 1. Validation of input data (Transaction object id is missing) -> 422
     */
    public function testWebhookReturnsUnprocessableEntityIfMissingToken(): void
    {
        $this->logInAsClient();

        // Sending invalid JSON without ['object']['id']
        $this->client->request(
            'POST',
            '/api/webhooks/payment',
            [], [],
            $this->getAuthHeaders(),
            json_encode([
                'type' => 'payment.succeeded',
                'object' => [
                    'amount' => 2000
                ]
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $responseData);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertStringContainsString('ignored', $responseData['status']);
        $this->assertStringContainsString(
            'The transaction was ignored. Reason: Missing payment token.',
            $responseData['message']
        );
    }

    /**
     * 2. Transaction not found in the database -> 404
     */
    public function testWebhookReturnsNotFoundIfTransactionDoesNotExist(): void
    {
        $this->logInAsClient();

        $this->client->request(
            'POST',
            '/api/webhooks/payment',
            [], [],
            $this->getAuthHeaders(),
            json_encode([
                'type' => 'payment.succeeded',
                'object' => [
                    'id' => 'non_existent_token_in_db',
                    'amount' => 3000
                ]
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $responseData);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertStringContainsString('error', $responseData['status']);
        $this->assertStringContainsString(
            'Transaction not found. An incorrect payment token have been transmitted.',
            $responseData['message']
        );
    }

    /**
     * 3. Incorrect amount or unsupported event type -> 417
     */
    public function testWebhookReturnsExpectationFailedOnIncorrectAmount(): void
    {
        $this->logInAsClient();

        // Creating a test transaction for the amount of 4000
        $booking = $this->createBaseBooking();
        $transaction = $this->createBaseTransaction($booking, 'ch_webhook_fail_1', 4000);

        // The gateway sends an incorrect amount (1500 instead of 4000)
        $this->client->request(
            'POST',
            '/api/webhooks/payment',
            [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'type' => 'payment.succeeded',
                'object' => [
                    'id' => 'ch_webhook_fail_1',
                    'amount' => 1500
                ]
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_EXPECTATION_FAILED);
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $responseData);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertStringContainsString('error', $responseData['status']);
        $this->assertStringContainsString(
            'Transaction failed. Unsupported event type and/or incorrect (or missing) amount have been transmitted.',
            $responseData['message']
        );
    }

    /***********************************************************
     * Auxiliary methods for creating data for isolating tests *
     **********************************************************/

    /**
     * Creates a base booking for testing purposes
     */
    private function createBaseBooking(): Booking
    {
        $booking = new Booking();
        $booking
            ->setUser($this->testUser)
            ->setResource($this->testResource)
            ->setStatus(BookingStatus::PENDING)
            ->setStartedAt($this->baseTime->modify('+1 hour'))
            ->setEndedAt($this->baseTime->modify('+2 hours'))
            ->setTotalPrice(1000);
        $this->em->persist($booking);

        return $booking;
    }

    /**
     * Creates a base transaction for testing purposes
     */
    private function createBaseTransaction(
        Booking $booking,
        string $externalId,
        int $amount,
        string $status = 'created'
    ): PaymentTransaction
    {
        $transaction = new PaymentTransaction();
        $transaction->setBooking($booking);
        $transaction->setExternalId($externalId);
        $transaction->setAmount($amount);
        $transaction->setStatus($status);
        $this->em->persist($transaction);
        $this->em->flush();
        return $transaction;
    }
}
