<?php

namespace App\MessageHandler;

use App\Enum\BookingStatus;
use App\Message\CheckCompletionMessage;
use App\Message\SendEmailNotificationMessage;
use App\Repository\BookingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class CheckCompletionHandler
{
    public function __construct(
        private readonly BookingRepository      $bookingRepository,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface    $messageBus,
        private readonly LoggerInterface        $logger
    ) {}

    public function __invoke(CheckCompletionMessage $message): void
    {
        $booking = $this->bookingRepository->find($message->getBookingId());

        // At the end of the booking time (with the CHECKED_IN status)
        if ($booking && $booking->getStatus() === BookingStatus::CHECKED_IN) {
            $booking->setStatus(BookingStatus::COMPLETED);
            $this->em->flush();

            // Free up space and notify
            try {
                $this->messageBus->dispatch(new SendEmailNotificationMessage($booking->getId(), BookingStatus::COMPLETED));
            } catch (ExceptionInterface $e) {
                $this->logger->error('Could not put the task to send an email to the queue: ' . $e->getMessage());
            }
        }
    }
}
