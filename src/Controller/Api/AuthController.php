<?php

namespace App\Controller\Api;

use App\DTO\RegistrationInput;
use App\Service\RegistrationService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

class AuthController extends AbstractController
{
    public function __construct(
        private readonly RegistrationService $registrationService
    ) {
    }

    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(
        #[MapRequestPayload] RegistrationInput $input
    ): JsonResponse {
        try {
            $user = $this->registrationService->register($input);

            return $this->json([
                'id' => $user->getId(),
                'fullName' => $user->getFullName(),
                'email' => $user->getEmail(),
                'message' => 'User registered successfully',
            ], Response::HTTP_CREATED);
        } catch (UniqueConstraintViolationException) {
            // Race Condition
            // simultaneous registration with the same emails
            return $this->json([
                'message' => 'Registration failed',
                'errors' => ['email' => 'This email is already registered']
            ], Response::HTTP_CONFLICT);
        }
    }
}
