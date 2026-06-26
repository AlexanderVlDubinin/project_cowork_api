<?php

namespace App\Controller\Api;

use App\Service\PaymentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class WebhookController extends AbstractController
{
    #[Route('/api/webhooks/payment', methods: ['POST'])]
    public function handlePaymentWebhook(
        Request $request,
        PaymentService $paymentService,
    ): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        $eventType = $payload['type'] ?? null;
        $externalId = $payload['object']['id'] ?? null;
        $amount = (int)($payload['object']['amount'] ?? null);

        if (!$externalId) {
            return $this->json([
                'status' => 'ignored',
                'message' => 'The transaction was ignored. Reason: Missing payment token.'
            ], 422);
        }

        $result = $paymentService->confirmPaymentViaWebhook($eventType, $externalId, $amount, $payload);

        return match ($result) {
            'already_processed' => $this->json([
                'status' => 'already_processed',
                'message' => 'Transaction has already been successfully processed.'
            ]),
            'failed' => $this->json([
                'status' => 'error',
                'message' => 'Transaction failed. Unsupported event type and/or incorrect (or missing) amount have been transmitted.'
            ], 417),
            'not_found' => $this->json([
                'status' => 'error',
                'message' => 'Transaction not found. An incorrect payment token have been transmitted.'
            ], 404),
            'confirmed' => $this->json([
                'status' => 'success',
                'message' => 'Booking confirmed'
            ]),
        };
    }
}
