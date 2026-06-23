<?php

namespace App\Controller\Api\Admin;

use App\Repository\UserRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class UserAdminController extends AbstractController
{
    #[Route('/api/admin/users', name: 'api_admin_users')]
    public function index(
        Request $request,
        PaginatorInterface $paginator,
        UserRepository $userRepository
    ): JsonResponse
    {
        $utype = $request->query->get('utype', 0);

        if ($utype) {
            $users = $userRepository->findOnlyRegularUsers();
        } else {
            $users = $userRepository->findAll();
        }

        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 10);
        $usersPagination = $paginator->paginate($users, $page, $limit);

        return $this->json(
            $usersPagination,
            Response::HTTP_OK,
            [],
            [
                'groups' => ['user:read']
            ]
        );
    }
}
