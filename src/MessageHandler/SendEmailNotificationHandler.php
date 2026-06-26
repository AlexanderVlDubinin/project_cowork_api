<?php

namespace App\MessageHandler;

use App\Enum\BookingStatus;
use App\Message\SendEmailNotificationMessage;
use App\Repository\BookingRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

// Simulates sending emails to a client via Symfony Mailer (Mailpit).
#[AsMessageHandler]
class SendEmailNotificationHandler
{
    public function __construct(
        private readonly BookingRepository $bookingRepository,
        private readonly MailerInterface   $mailer,
        private readonly LoggerInterface   $logger,
        private readonly string            $defaultFromEmail,
        private readonly string            $defaultFromName,
        private readonly int               $bookingPaymentDelay,
        private readonly string            $paymentBaseUrl,
        private readonly int                $noShowDelay,
    ) {}

    public function __invoke(SendEmailNotificationMessage $message): void
    {
        $booking = $this->bookingRepository->find($message->getBookingId());
        if (!$booking) {
            return;
        }

        $userEmail = $booking->getUser()->getEmail();
        $resourceTitle = $booking->getResource()->getTitle();
        $paymentUrl = $this->paymentBaseUrl . $message->getBookingId();

        $email = (new TemplatedEmail())
            ->from(new Address($this->defaultFromEmail, $this->defaultFromName))
            ->to($userEmail);

        $totalPrice = $booking->getTotalPrice() / 100; // Converting cents to dollars
        switch ($message->getType()) {
            case BookingStatus::PENDING:
                $email->subject('Your booking has been created! Waiting for payment')
                    ->htmlTemplate('emails/booking_created.html.twig')
                    ->context([
                        'resourceTitle' => $resourceTitle,
                        'paymentDelay' => $this->bookingPaymentDelay,
                        'paymentUrl' => $paymentUrl,
                        'totalPrice' => $totalPrice,
                    ]);
                break;

            case BookingStatus::EXPIRED:
                $email->subject('The booking payment period has expired')
                    ->htmlTemplate('emails/booking_expired.html.twig')
                    ->context([
                        'resourceTitle' => $resourceTitle,
                        'paymentDelay' => $this->bookingPaymentDelay,
                        'totalPrice' => $totalPrice,
                    ]);
                break;

            case BookingStatus::FAILED:
                $email->subject('Booking failed')
                    ->htmlTemplate('emails/booking_failed.html.twig')
                    ->context([
                        'resourceTitle' => $resourceTitle,
                        'totalPrice' => $totalPrice,
                    ]);
                break;

            case BookingStatus::CONFIRMED:
                $email->subject('Booking payment confirmed')
                    ->htmlTemplate('emails/booking_confirmed.html.twig')
                    ->context([
                        'resourceTitle' => $resourceTitle,
                        'totalPrice' => $totalPrice,
                        'startedAt' => $booking->getStartedAt()->format('Y-m-d H:i:s'),
                        'endedAt' => $booking->getEndedAt()->format('Y-m-d H:i:s'),
                    ]);
                break;

            case BookingStatus::CANCELLED:
                $email->subject('Booking cancelled')
                    ->htmlTemplate('emails/booking_cancelled.html.twig')
                    ->context([
                        'resourceTitle' => $resourceTitle,
                    ]);
                break;

            case BookingStatus::COMPLETED:
                $email->subject('Booking completed')
                    ->htmlTemplate('emails/booking_completed.html.twig')
                    ->context([
                        'resourceTitle' => $resourceTitle,
                    ]);
                break;

            case BookingStatus::NO_SHOW:
                $email->subject('Booking Cancelled (No-Show)')
                    ->htmlTemplate('emails/booking_no_show.html.twig')
                    ->context([
                        'resourceTitle' => $resourceTitle,
                        'noShowDelay' => $this->noShowDelay,
                    ]);
                break;
        }

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Could not send email: ' . $e->getMessage());
        }
    }
}
