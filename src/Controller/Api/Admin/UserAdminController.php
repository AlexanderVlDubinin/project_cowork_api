<?php

namespace App\Controller\Api\Admin;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class UserAdminController extends AbstractController
{
    #[Route('/api/admin/users', name: 'api_admin_users')]
    public function index(UserRepository $userRepository): JsonResponse
    {
        $users = $userRepository->findOnlyRegularUsers();

        return $this->json(
            $users,
            Response::HTTP_OK,
            [],
            [
                'groups' => ['user:read']
            ]
        );
    }
}
