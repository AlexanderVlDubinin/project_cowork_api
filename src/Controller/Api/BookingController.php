<?php

namespace App\Controller\Api;

use App\DTO\BookingInput;
use App\DTO\BookingListFilterInput;
use App\DTO\BookingOutput;
use App\Entity\Booking;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Message\SendEmailNotificationMessage;
use App\Repository\BookingRepository;
use App\Service\BookingCalendarService;
use App\Service\BookingManager;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', defaults: ['_format' => 'json'])]
final class BookingController extends AbstractController
{
    #[Route('/bookings', name: 'api_client_bookings', methods: ['GET'])]
    public function bookingsList(
        BookingRepository $bookingRepository,
        BookingCalendarService $calendarService,
        PaginatorInterface $paginator,
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

        $rawBookings = $bookingRepository->findBookingsList($isAdmin ? null : $user, $isAdmin ? $filters : null, $isAdmin);
        $preparedBookings = BookingOutput::bookingsOutputPrepare($rawBookings, $calendarService);

        $bookingsOutputPagination = $paginator->paginate(
            $preparedBookings,
                $filters->page ?? 1,
                $filters->limit ?? 10
        );

        return $this->json($bookingsOutputPagination);
    }

    #[Route('/booking', name: 'api_client_booking_create', methods: ['POST'])]
    public function createBooking(
        #[MapRequestPayload] BookingInput $dtoInput,
        BookingManager $bookingManager
    ): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User is not logged in or has the wrong class.');
        }

        $rawStart = $dtoInput->getStartedAtObject('UTC');

        try {
            // Passing the parameters for end-to-end parsing
            $booking = $bookingManager->createBookingFromParams($user, $dtoInput, $rawStart);
            $bookingOutput = BookingOutput::getBookingOutput($booking);
            $jsonDataForReturn = $this->json($bookingOutput, Response::HTTP_CREATED);
        } catch (\LogicException $e) {
            $jsonDataForReturn = $this->json([
                'message' => 'Booking creation failed, date error',
                'errors' => ['error' => $e->getMessage()]
            ], Response::HTTP_CONFLICT);
        } catch (ConflictHttpException $e) {
            $jsonDataForReturn = $this->json([
                'message' => 'Booking creation failed, overlap error',
                'errors' => ['error' => $e->getMessage()]
            ], Response::HTTP_CONFLICT);
        }

        return $jsonDataForReturn;
    }

    #[Route('/booking/{id}', name: 'api_client_booking_cancel', methods: ['DELETE'])]
    public function cancelBooking(
        Booking $booking,
        EntityManagerInterface $entityManager,
        MessageBusInterface $messageBus,
        LoggerInterface $logger
    ): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User is not logged in or has the wrong class.');
        }

        if ($booking->getUser()->getId() !== $user->getId() && !$this->isGranted('ROLE_ADMIN')) { // Voter ???
            throw $this->createAccessDeniedException('You do not have permission to cancel this booking.');
        }

        if (in_array($booking->getStatus(), [
            BookingStatus::CANCELLED,
            BookingStatus::EXPIRED,
            BookingStatus::FAILED,
            BookingStatus::NO_SHOW
        ])) {
            return $this->json([
                'message' => 'Booking ' . $booking->getStatus()->value,
                'errors' => ['error' => 'You cannot cancel a booking with the `' . $booking->getStatus()->value . '` status.']
            ], Response::HTTP_BAD_REQUEST);
        }

        $booking->setStatus(BookingStatus::CANCELLED);
        $entityManager->flush();

        //Sending an asynchronous cancellation notification
        $bookingId = $booking->getId();
        try {
            $messageBus->dispatch(new SendEmailNotificationMessage($bookingId, BookingStatus::CANCELLED));
        } catch (ExceptionInterface $e) {
            $logger->critical('Could not place message in Messenger queue: ' . $e->getMessage());
        }

        return $this->json(['message' => 'Booking cancelled successfully.'], Response::HTTP_OK);
    }
}
