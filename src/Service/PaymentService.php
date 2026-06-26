<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\PaymentTransaction;
use App\Enum\BookingStatus;
use App\Message\CheckNoShowMessage;
use App\Message\CheckCompletionMessage;
use App\Message\SendEmailNotificationMessage;
use App\Repository\PaymentTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class PaymentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface    $messageBus,
        private readonly PaymentTransactionRepository $transactionRepository,
        private readonly LoggerInterface $logger,
        private readonly int $noShowDelay,
    ) {}

    /**
     * Payment step 1: The intention to pay. Generating a transaction with a fake external_id
     */
    public function initiatePayment(Booking $booking): PaymentTransaction
    {
        if ($booking->getStatus() !== BookingStatus::PENDING) {
            throw new \LogicException('Payment can only be initiated for bookings awaiting payment (pending status).');
        }

        $transaction = new PaymentTransaction();
        $transaction->setBooking($booking);
        $transaction->setAmount($booking->getTotalPrice());
        $transaction->setStatus('created');

        // Simulation of integration: we generate ch_fake_... (fake external_id) instead of calling Stripe API
        $transaction->setExternalId('ch_fake_' . bin2hex(random_bytes(6)));

        $this->em->persist($transaction);
        $this->em->flush();

        return $transaction;
    }

    /**
     * Payment step 2: The transfer of the booking to the CONFIRMED status (called from the WebhookController)
     */
    public function confirmPaymentViaWebhook(string $eventType, string $externalId, int $amount, array $rawPayload): string
    {
        // Find the transaction by payment_token (external_id)
        $transaction = $this->transactionRepository->findOneBy(['externalId' => $externalId]);

        if (!$transaction) {
            return 'not_found';
        }

        // Protection against repeated webhooks (Idempotence)
        if ($transaction->getStatus() === 'success') {
            return 'already_processed';
        }

        $booking = $transaction->getBooking();
        $bookingId = $booking->getId();

        // transaction fails if the token is incorrect and/or the amount is incorrect (or missing)
        if ( $eventType !== 'payment.succeeded' || !$amount || ($transaction->getAmount() !== $amount) ) {
            $transaction->setStatus('failed');
            $transaction->setPayload($rawPayload);
            $this->em->flush();

            return 'failed';
        }

        // Updating the transaction
        $transaction->setStatus('success');
        $transaction->setPayload($rawPayload);

        // Updating the related booking
        // $booking = $transaction->getBooking();
        $booking->setStatus(BookingStatus::CONFIRMED);

        $this->em->flush();

        // $bookingId = $booking->getId();

        // Generating asynchronous events
        try {
            $this->messageBus->dispatch(new SendEmailNotificationMessage($bookingId, BookingStatus::CONFIRMED));

            $noShowDelay = ($booking->getStartedAt()->getTimestamp() + $this->noShowDelay * 60) - time();
            if ($noShowDelay > 0) {
                $this->messageBus->dispatch(new CheckNoShowMessage($bookingId), [new DelayStamp($noShowDelay * 1000)]);
            }

            $completionDelay = $booking->getEndedAt()->getTimestamp() - time();
            if ($completionDelay > 0) {
                $this->messageBus->dispatch(new CheckCompletionMessage($bookingId), [new DelayStamp($completionDelay * 1000)]);
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error('Booking task planning error: ' . $e->getMessage());
        }

        return 'confirmed';
    }
}
