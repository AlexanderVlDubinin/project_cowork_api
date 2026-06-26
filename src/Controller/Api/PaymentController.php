<?php

namespace App\Controller\Api;

use App\Entity\Booking;
use App\Service\PaymentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class PaymentController extends AbstractController
{
    #[Route('/api/booking/{id}/pay', methods: ['POST'])]
    public function pay(
        Booking $booking,
        PaymentService $paymentService,
        string $fakePaymentGateway,
    ): JsonResponse {
        try {
            $transaction = $paymentService->initiatePayment($booking);

            return $this->json([
                'status' => 'pending',
                'payment_token' => $transaction->getExternalId(),
                'redirect_url' => $fakePaymentGateway . $transaction->getExternalId()
            ]);
        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }
}
