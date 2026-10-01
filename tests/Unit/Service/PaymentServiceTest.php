<?php

namespace App\Tests\Unit\Service;

use App\Entity\Booking;
use App\Entity\PaymentTransaction;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Message\CheckCompletionMessage;
use App\Message\CheckNoShowMessage;
use App\Message\SendEmailNotificationMessage;
use App\Repository\PaymentTransactionRepository;
use App\Service\PaymentService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class PaymentServiceTest extends TestCase
{
    use ClockSensitiveTrait; // trait is used for flexible time simulation (mockTime())

    #[AllowMockObjectsWithoutExpectations] // to avoid notices about missing expectations MessageBusInterface & LoggerInterface
    public function testConfirmPaymentViaWebhookSuccessDispatchesMessages(): void
    {
        // 1. Creating dependency stubs
        $em = $this->createMock(EntityManagerInterface::class);
        $messageBus = $this->createMock(MessageBusInterface::class);
        $repository = $this->createMock(PaymentTransactionRepository::class);
        $logger = $this->createMock(LoggerInterface::class);
        $baseTime = new \DateTimeImmutable('next week monday 12:00:00', new \DateTimeZone('UTC'));
        self::mockTime($baseTime);

        $testUser = new User();
        $testResource = new Resource();

        // 2. Preparing test data (Entities)
        $booking = new Booking();
        $booking
            ->setUser($testUser)
            ->setResource($testResource)
            ->setStatus(BookingStatus::CONFIRMED)
            ->setStartedAt($baseTime->modify('+2 hour')) // baseTime+2 hour
            ->setEndedAt($baseTime->modify('+4 hour')) // baseTime+4 hour
            ->setTotalPrice(1500);

        $transaction = new PaymentTransaction();
        $transaction
            ->setBooking($booking)
            ->setAmount(5000)
            ->setStatus('created');

        // Setting up the repository so that it returns the transaction
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['externalId' => 'ch_test_123'])
            ->willReturn($transaction);

        // Setting up the EntityManager, waiting for the flush call when updating the statuses
        $em->expects($this->once())->method('flush');

        // An array in which all sent messages will be collected for verification
        $dispatchedMessages = [];

        // Setting up interception of all dispatch() calls
        // Waiting for exactly 3 messages - for SendEmailNotificationMessage, CheckNoShowMessage, CheckCompletionMessage
        $messageBus->expects($this->exactly(3))
        ->method('dispatch')
            ->willReturnCallback(function ($message, array $stamps = []) use (&$dispatchedMessages) {
                // Saving the captured message in an array
                $dispatchedMessages[] = $message;

                // The dispatch method must return the Envelope object.
                return new Envelope($message, $stamps);
            });

        // Initializing the service (the noShowDelay parameter is set to 10 minutes)
        $paymentService = new PaymentService($em, $messageBus, $repository, $logger, 10);

        // 3. Executing the method
        $rawPayload = ['type' => 'payment.succeeded', 'object' => ['id' => 'ch_test_123', 'amount' => 5000]];
        $result = $paymentService->confirmPaymentViaWebhook('payment.succeeded', 'ch_test_123', 5000, $rawPayload);

        // 4. Checking for changes in the state of objects
        $this->assertSame('confirmed', $result);
        $this->assertSame('success', $transaction->getStatus());
        $this->assertSame(BookingStatus::CONFIRMED, $booking->getStatus());

        // Checking that exactly those 3 messages from the PaymentService that are needed have flown into the queue.
        $this->assertCount(3, $dispatchedMessages, 'Exactly 3 messages must be sent to the bus.');
        $this->assertInstanceOf(SendEmailNotificationMessage::class, $dispatchedMessages[0]);
        $this->assertInstanceOf(CheckNoShowMessage::class, $dispatchedMessages[1]);
        $this->assertInstanceOf(CheckCompletionMessage::class, $dispatchedMessages[2]);
    }
}
