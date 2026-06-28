<?php

namespace App\Tests\Integration\Controller;

use App\Entity\Booking;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\ResourceType;
use App\Message\CheckCompletionMessage;
use App\Message\CheckNoShowMessage;
use App\MessageHandler\CheckCompletionHandler;
use App\MessageHandler\CheckNoShowHandler;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PaymentWorkflowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Booking $booking;
    private UserPasswordHasherInterface $passwordHasher;
    private string $jwtToken;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->setupTestData();
    }

    private function setupTestData(): void
    {
        // DB clear
        // Clearing old users with the same email address
        $existingUser = $this->em->getRepository(User::class)->findOneBy(['email' => 'client@example.com']);
        if ($existingUser) {
            $this->em->remove($existingUser);
        }
        // Cleaning up old test resources to avoid overgrowth of the database
        $existingResources = $this->em->getRepository(Resource::class)->findBy(['title' => 'Test Desk № 1']);
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
        $user = new User();
        $user->setFullName('Test User 1');
        $user->setEmail('client@example.com');
        $hashedPassword = $this->passwordHasher->hashPassword($user, 'password123');
        $user->setPassword($hashedPassword);
        $user->setRoles(['ROLE_USER']);
        $this->em->persist($user);

        // test resource
        $resource = new Resource();
        $resource->setTitle('Test Desk № 1');
        $resource->setType(ResourceType::DESK);
        $resource->setDescription('Test Desk № 1 Description');
        $resource->setIsActive(true);
        $resource->setPricePerHour(500);
        $this->em->persist($resource);

        // test booking
        $this->booking = new Booking();
        $this->booking
            ->setUser($user)
            ->setResource($resource)
            ->setStartedAt(new \DateTimeImmutable('now + 1 hour'))
            ->setEndedAt(new \DateTimeImmutable('now + 2 hours'))
            ->setTotalPrice(500)
            ->setStatus(BookingStatus::PENDING);

        $this->em->persist($this->booking);
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
     * Testing the scenario of successful booking payment by a client using a webhook
     * and idempotence verification (repeated gateway webhook should not change the logic).
     */
    public function testFullPaymentAndWebhookWorkflow(): void
    {
        $this->logInAsClient();

        // Initiation of payment by the client
        $this->client->request(
            'POST',
            sprintf('/api/booking/%s/pay', $this->booking->getId()->toString()),
            [], [],
            $this->getAuthHeaders(),
        );

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());
        $initiateData = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertEquals('pending', $initiateData['status']);
        $this->assertArrayHasKey('payment_token', $initiateData);
        $fakeToken = $initiateData['payment_token']; // Достаем ch_fake_...

        // Simulating sending a webhook by a payment system
        $webhookPayload = [
            'type' => 'payment.succeeded',
            'object' => [
                'id' => $fakeToken,
                'amount' => 500
            ]
        ];

        $this->client->request(
            'POST',
            '/api/webhooks/payment',
            [], [],
            $this->getAuthHeaders(),
            json_encode($webhookPayload)
        );

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());
        $webhookData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('success', $webhookData['status']);

        // ATTENTION! Restarting the entity manager and finding the booking again.
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        /** @var Booking $updatedBooking */
        $updatedBooking = $this->em->getRepository(Booking::class)->find($this->booking->getId());

        // checking the existence of $updatedBooking
        $this->assertNotNull($updatedBooking, 'The booking was not found in the database after the webhook');
        // Checking that the booking has been changed to confirmed in the database.
        $this->assertEquals(BookingStatus::CONFIRMED, $updatedBooking->getStatus());

        // Idempotence check (repeated gateway webhook should not change the logic)
        $this->client->request(
            'POST',
            '/api/webhooks/payment',
            [], [],
            $this->getAuthHeaders(),
            json_encode($webhookPayload)
        );

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());
        $repeatData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('already_processed', $repeatData['status']);
    }

    /**
     * Scenario test 'ignored' (Missing externalId)
     */
    public function testWebhookIgnoredWhenExternalIdIsMissing(): void
    {
        $this->logInAsClient();

        $webhookPayload = [
            'type' => 'payment.succeeded',
            'object' => [
                // The 'id' was intentionally omitted
                'amount' => 1500
            ]
        ];

        $this->client->request(
            'POST',
            '/api/webhooks/payment',
            [], [],
            $this->getAuthHeaders(),
            json_encode($webhookPayload)
        );

        // Checking the 422 status and the response structure
        $this->assertEquals(422, $this->client->getResponse()->getStatusCode());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('ignored', $data['status']);
        $this->assertStringContainsString('Missing payment token', $data['message']);
    }

    /**
     * Test of the 'not_found' scenario (Transaction not found in the database)
     */
    public function testWebhookReturnsNotFoundForUnknownExternalId(): void
    {
        $this->logInAsClient();

        $webhookPayload = [
            'type' => 'payment.succeeded',
            'object' => [
                'id' => 'ch_non_existent_token_12345', // A token that is not in the database
                'amount' => 1500
            ]
        ];

        $this->client->request(
            'POST',
            '/api/webhooks/payment',
            [], [],
            $this->getAuthHeaders(),
            json_encode($webhookPayload)
        );

        // Checking the 404 status and response structure
        $this->assertEquals(404, $this->client->getResponse()->getStatusCode());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertStringContainsString('Transaction not found', $data['message']);
    }

    /**
     * The 'failed' scenario test (Event type is not payment.succeeded)
     */
    public function testWebhookReturnsFailedForUnsupportedEventType(): void
    {
        $this->logInAsClient();

        // First, generate a transaction so that the externalId exists
        $this->client->request(
            'POST',
            sprintf('/api/booking/%s/pay', $this->booking->getId()->toString()),
            [], [],
            $this->getAuthHeaders(),
        );
        $initiateData = json_decode($this->client->getResponse()->getContent(), true);
        $token = $initiateData['payment_token'];

        $webhookPayload = [
            'type' => 'payment.failed', // Unsupported event type
            'object' => [
                'id' => $token,
                'amount' => 1500
            ]
        ];

        $this->client->request(
            'POST',
            '/api/webhooks/payment',
            [], [],
            $this->getAuthHeaders(),
            json_encode($webhookPayload)
        );

        // Checking the 417 Expectation Failed status
        $this->assertEquals(417, $this->client->getResponse()->getStatusCode());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertStringContainsString('Unsupported event type', $data['message']);
    }

    /**
     * The 'failed' scenario test (Incorrect amount or amount equal to 0)
     */
    public function testWebhookReturnsFailedWhenAmountIsIncorrectOrZero(): void
    {
        $this->logInAsClient();

        // Initiating a payment to receive a valid token
        $this->client->request(
            'POST',
            sprintf('/api/booking/%s/pay', $this->booking->getId()->toString()),
            [], [],
            $this->getAuthHeaders(),
        );
        $initiateData = json_decode($this->client->getResponse()->getContent(), true);
        $token = $initiateData['payment_token'];

        // Sending a request with an incorrect amount (in the database - 1500)
        $payloadWithWrongAmount = [
            'type' => 'payment.succeeded',
            'object' => [
                'id' => $token,
                'amount' => 9999 // Incorrect amount (Fraud/Gateway Error)
            ]
        ];

        $this->client->request(
            'POST',
            '/api/webhooks/payment',
            [], [],
            $this->getAuthHeaders(),
            json_encode($payloadWithWrongAmount)
        );

        $this->assertEquals(417, $this->client->getResponse()->getStatusCode());
        $dataWrong = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('error', $dataWrong['status']);
        $this->assertStringContainsString('Transaction failed', $dataWrong['message']);

        // Sending a zero-sum request.
        $payloadWithZeroAmount = [
            'type' => 'payment.succeeded',
            'object' => [
                'id' => $token,
                'amount' => 0 // Zero or missing amount
            ]
        ];

        $this->client->request(
            'POST',
            '/api/webhooks/payment',
            [], [],
            $this->getAuthHeaders(),
            json_encode($payloadWithZeroAmount)
        );

        $this->assertEquals(417, $this->client->getResponse()->getStatusCode());
        $dataWrong = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('error', $dataWrong['status']);
        $this->assertStringContainsString('Transaction failed', $dataWrong['message']);
    }

    /**
     * Successful Check-In at the allowed time
     */
    public function testCheckInSuccessWithinAllowedBuffer(): void
    {
        $this->logInAsClient();

        // Transfer the booking to CONFIRMED (simulate a successful payment)
        $this->booking->setStatus(BookingStatus::CONFIRMED);

        // Set the start time of the booking so that the current moment "now" falls into the buffer.
        // For example, the booking will start in 3 minutes. With a buffer of 5 minutes— the check-in is already allowed.
        $this->booking->setStartedAt(new \DateTimeImmutable('now + 3 minutes', new \DateTimeZone('UTC')));
        $this->em->flush();

        $this->client->request(
            'POST',
            sprintf('/api/booking/%s/check_in', $this->booking->getId()->toString()),
            [], [],
            $this->getAuthHeaders(),
        );

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('checked_in', $data['status']);

        // ATTENTION! Restarting the entity manager and finding the booking again.
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        /** @var Booking $updatedBooking */
        $updatedBooking = $this->em->getRepository(Booking::class)->find($this->booking->getId());

        // checking the existence of $updatedBooking
        $this->assertNotNull($updatedBooking, 'The booking was not found in the database');
        // Checking the status in the database
        $this->assertEquals(BookingStatus::CHECKED_IN, $updatedBooking->getStatus());
    }

    /**
     * Check-In error (Too early)
     */
    public function testCheckInFailsWhenTooEarly(): void
    {
        $this->logInAsClient();

        $this->booking->setStatus(BookingStatus::CONFIRMED);
        // The booking will only start in 5 hours (it will go far beyond the 5-minute buffer)
        $this->booking->setStartedAt(new \DateTimeImmutable('now + 5 hours', new \DateTimeZone('UTC')));
        $this->em->flush();

        $this->client->request(
            'POST',
            sprintf('/api/booking/%s/check_in', $this->booking->getId()->toString()),
            [], [],
            $this->getAuthHeaders(),
        );

        $this->assertEquals(400, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('too early', $data['error']);
    }

    /**
     * Automatic No-Show (Client's Non-appearance)
     * The situation is simulated: the booking is confirmed, the start time has passed (+11 minutes -- noShowDelay + 1),
     * the customer has NOT activated check_in. Starting the Queue Handler manually.
     */
    public function testNoShowHandlerCancelsBooking(): void
    {
        $this->logInAsClient();

        $this->booking->setStatus(BookingStatus::CONFIRMED);
        // Moving the booking start time to the past (11 minutes ago)
        // This means that the 10-minute check_in waiting window has already closed.
        $this->booking->setStartedAt(new \DateTimeImmutable('now - 11 minutes', new \DateTimeZone('UTC')));
        $this->em->flush();

        // Getting the Handler from the Symfony container
        /** @var CheckNoShowHandler $handler */
        $handler = static::getContainer()->get(CheckNoShowHandler::class);

        // Creating a message that Messenger was supposed to execute in the background
        $message = new CheckNoShowMessage($this->booking->getId());

        // Forcibly calling the handler
        $handler($message);

        // ATTENTION! Restarting the entity manager and finding the booking again.
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        /** @var Booking $updatedBooking */
        $updatedBooking = $this->em->getRepository(Booking::class)->find($this->booking->getId());

        // checking the existence of $updatedBooking
        $this->assertNotNull($updatedBooking, 'The booking was not found in the database');
        // Checking that the booking status has changed to NO_SHOW
        $this->assertEquals(BookingStatus::NO_SHOW, $updatedBooking->getStatus());
    }

    /**
     * Automatic Completion (Completion of work time)
     * It is simulated that the booking time has come to an end. Launching the Handler.
     */
    public function testCompletionHandlerFinishesBooking(): void
    {
        $this->logInAsClient();

        // The client was successfully present at the workplace
        $this->booking->setStatus(BookingStatus::CHECKED_IN);
        // Shifting the end time of the booking to the past (1 minute ago), as if the time has run out
        $this->booking->setEndedAt(new \DateTimeImmutable('now - 1 minute', new \DateTimeZone('UTC')));
        $this->em->flush();

        /** @var CheckCompletionHandler $handler */
        $handler = static::getContainer()->get(CheckCompletionHandler::class);

        $message = new CheckCompletionMessage($this->booking->getId());
        $handler($message);

        // ATTENTION! Restarting the entity manager and finding the booking again.
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        /** @var Booking $updatedBooking */
        $updatedBooking = $this->em->getRepository(Booking::class)->find($this->booking->getId());

        // checking the existence of $updatedBooking
        $this->assertNotNull($updatedBooking, 'The booking was not found in the database');
        // Checking that the status has become COMPLETED, the place has been vacated
        $this->assertEquals(BookingStatus::COMPLETED, $updatedBooking->getStatus());
    }
}
