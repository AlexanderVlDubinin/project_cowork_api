<?php

namespace App\Controller\Api;

use App\Enum\ResourceType;
use App\Repository\ResourceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ResourceClientController extends AbstractController
{
    #[Route('/api/resources', name: 'api_client_resources', methods: ['GET'])]
    public function index(Request $request, ResourceRepository $repository): JsonResponse
    {
        // $userRole = $this->getUser()->getRoles();
        $typeParam = $request->query->get('type');
        $enumType = null;
        //$date = $request->query->get('date');

        if ($typeParam) {
            $enumType = ResourceType::tryFrom($typeParam);

            if (!$enumType) {
                $allowedValues = array_map(fn($case) => $case->value, ResourceType::cases());

                return $this->json([
                    'message' => 'Validation failed',
                    'errors' => [
                        'type' => sprintf(
                            "Invalid value '%s'. Allowed values are: %s.",
                            $typeParam,
                            implode(', ', $allowedValues)
                        )
                    ],
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        $resources = $repository->findByFilters($enumType, true);

        return $this->json(
            $resources,
            Response::HTTP_OK,
            [],
            [
                'groups' => ['resource:read']
            ]
        );
    }
}
