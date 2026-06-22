<?php

namespace App\Controller\Api;

use App\DTO\BookingInput;
use App\DTO\BookingListFilterInput;
use App\Entity\Booking;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Repository\BookingRepository;
use App\Repository\ResourceRepository;
use App\Service\BookingManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class BookingController extends AbstractController
{
    #[Route('/api/bookings', name: 'api_client_bookings', methods: ['GET'])]
    public function bookingsList(
        BookingRepository $bookingRepository,
        Security $security,
        #[MapQueryString(validationFailedStatusCode: 400)] ?BookingListFilterInput $filters = null
    ): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User is not logged in or has the wrong class.');
        }

        $filters ??= new BookingListFilterInput();

        $isAdmin = $security->isGranted('ROLE_ADMIN');
        $bookingsOutput = $bookingRepository->findBookingsList($isAdmin ? null : $user, $isAdmin ? $filters : null, $isAdmin);

        return $this->json($bookingsOutput);
    }

    #[Route('/api/booking', name: 'api_client_booking_create', methods: ['POST'])]
    public function createBooking(
        #[MapRequestPayload] BookingInput $dtoInput,
        ResourceRepository $resourceRepository,
        BookingManager $bookingManager
    ): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User is not logged in or has the wrong class.');
        }

        $resource = $resourceRepository->find($dtoInput->resourceId);
        if (!$resource || !$resource->isActive()) {
            return $this->json([
                'message' => 'Available resource not found',
                'errors' => ['error' => 'Resource not found or unavailable for booking.']
            ], Response::HTTP_NOT_FOUND);
        }

        $startedAt = $dtoInput->getStartedAtObject('UTC');
        $endedAt = $dtoInput->getEndedAtObject('UTC');

        try {
            $bookingOutput = $bookingManager->createBooking($user, $resource, $startedAt, $endedAt);
            return $this->json($bookingOutput, Response::HTTP_CREATED);
        } catch (\LogicException $e) {
            return $this->json([
                'message' => 'Booking creation failed',
                'errors' => ['error' => $e->getMessage()]
            ], Response::HTTP_CONFLICT);
        }
    }

    #[Route('/api/booking/{id}', name: 'api_client_booking_cancel', methods: ['DELETE'])]
    public function cancelBooking(Booking $booking, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('User is not logged in or has the wrong class.');
        }

        if ($booking->getUser()->getId() !== $user->getId() && !$this->isGranted('ROLE_ADMIN')) { // Voter ???
            throw new AccessDeniedException('You do not have permission to cancel this booking.');
        }

        if ($booking->getStatus() === BookingStatus::CANCELLED) {
            return $this->json([
                'message' => 'Booking cancelled',
                'errors' => ['error' => 'This booking is already cancelled.']
            ], Response::HTTP_BAD_REQUEST);
        }

        $booking->setStatus(BookingStatus::CANCELLED);
        $entityManager->flush();

        return $this->json(['message' => 'Booking cancelled successfully.'], Response::HTTP_OK);
    }
}
