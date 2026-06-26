<?php

namespace App\MessageHandler;

use App\Enum\BookingStatus;
use App\Message\CheckNoShowMessage;
use App\Message\SendEmailNotificationMessage;
use App\Repository\BookingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class CheckNoShowHandler
{
    public function __construct(
        private readonly BookingRepository      $bookingRepository,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface    $messageBus,
        private readonly LoggerInterface        $logger
    ) {}

    public function __invoke(CheckNoShowMessage $message): void
    {
        $booking = $this->bookingRepository->find($message->getBookingId());

        // If the customer has not called /check_in within 10 minutes from the start of the booking (with the CONFIRMED status)
        if ($booking && $booking->getStatus() === BookingStatus::CONFIRMED) {
            $booking->setStatus(BookingStatus::NO_SHOW);
            $this->em->flush();

            // Free up space and notify
            try {
                $this->messageBus->dispatch(new SendEmailNotificationMessage($booking->getId(), BookingStatus::NO_SHOW));
            } catch (ExceptionInterface $e) {
                $this->logger->error('Could not put the task to send an email to the queue: ' . $e->getMessage());
            }
        }
    }
}
