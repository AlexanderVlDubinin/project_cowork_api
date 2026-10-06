<?php

namespace App\Controller\Api;

use App\DTO\ResourceListFilterInput;
use App\Service\ResourceAvailabilityService;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

final class ResourceClientController extends AbstractController
{
    #[Route('/api/resources', name: 'api_client_resources', defaults: ['_format' => 'json'], methods: ['GET'])]
    public function index(
        PaginatorInterface $paginator,
        ResourceAvailabilityService $availabilityService,
        #[MapQueryString(validationFailedStatusCode: 400)] ResourceListFilterInput $filters
    ): JsonResponse
    {
        $availableResources = $availabilityService->getAvailableResourcesData($filters);

        $responseDataPagination = $paginator->paginate(
            $availableResources,
            $filters->page ?? 1,
            $filters->limit ?? 10
        );

        return $this->json(
            $responseDataPagination,
            Response::HTTP_OK,
            [],
            [
                'groups' => ['resource:read']
            ]
        );
    }
}
