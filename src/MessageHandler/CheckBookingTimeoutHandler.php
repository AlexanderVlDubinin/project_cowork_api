<?php

namespace App\MessageHandler;

use App\Enum\BookingStatus;
use App\Message\CheckBookingTimeoutMessage;
use App\Message\SendEmailNotificationMessage;
use App\Repository\BookingRepository;
use App\Repository\PaymentTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

// It is triggered <BOOKING_PAYMENT_DELAY> minutes after the booking is created.
// If the status is still PENDING, it switches it to EXPIRED and sends an event for email.
#[AsMessageHandler]
class CheckBookingTimeoutHandler
{
    public function __construct(
        private readonly BookingRepository      $bookingRepository,
        private readonly PaymentTransactionRepository $transactionRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
        private readonly MessageBusInterface $messageBus
    ) {}

    public function __invoke(CheckBookingTimeoutMessage $message): void
    {
        $booking = $this->bookingRepository->find($message->getBookingId());

        // If the booking has not been found or has already been paid/cancelled by the client, do nothing.
        if (!$booking || $booking->getStatus() !== BookingStatus::PENDING) {
            return;
        }

        $transactions = $this->transactionRepository->findBy(['booking' => $booking->getId()]);

        $status = empty($transactions) ? BookingStatus::EXPIRED : BookingStatus::FAILED;

        // Changing the status to expired or failed
        $booking->setStatus($status);
        $this->em->flush();

        // Generating an asynchronous email notification event
        try {
            $this->messageBus->dispatch(
                new SendEmailNotificationMessage($booking->getId(), $status)
            );
        } catch (ExceptionInterface $e) {
            $this->logger->error('Could not put the expiration email in the queue: ' . $e->getMessage(), [
                'booking_id' => $booking->getId()->toString()
            ]);
        }
    }
}
