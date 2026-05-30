<?php

namespace App\Controller\Api;

use App\DTO\RegistrationInput;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class AuthController extends AbstractController
{
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(
        #[MapRequestPayload] RegistrationInput $input,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $user = new User();

        $user->setFullName($input->fullName);
        $user->setEmail($input->email);
        $hashedPassword = $passwordHasher->hashPassword($user, $input->password);
        $user->setPassword($hashedPassword);

        // dd($user);

        $entityManager->persist($user);
        $entityManager->flush();

        return $this->json([
            'id' => $user->getId(),
            'fullName' => $user->getFullName(),
            'email' => $user->getEmail(),
            'message' => 'User registered successfully',
        ], JsonResponse::HTTP_CREATED);
    }
}
