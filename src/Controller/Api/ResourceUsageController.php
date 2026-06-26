<?php

namespace App\Controller\Api;

use App\Entity\Booking;
use App\Enum\BookingStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ResourceUsageController extends AbstractController
{
    #[Route('/api/booking/{id}/check_in', methods: ['POST'])]
    public function checkIn(
        Booking $booking,
        EntityManagerInterface $em,
        int $bookingTechBreak,
    ): JsonResponse {
        if ($booking->getStatus() !== BookingStatus::CONFIRMED) {
            return $this->json(['error' => 'Check-in is not possible. The booking must be confirmed (paid).'], 400);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        // Allow check-in only on the day/time of booking
        // (with a small buffer, for example, during a technical break before the start)
        if ($now < $booking->getStartedAt()->modify('-' . $bookingTechBreak . ' minutes')) {
            return $this->json(['error' => 'It is too early for a check-in.'], 400);
        }

        $booking->setStatus(BookingStatus::CHECKED_IN);
        $em->flush();

        return $this->json([
            'message' => 'You have successfully started using the resource.',
            'status' => $booking->getStatus()->value
        ]);
    }
}
