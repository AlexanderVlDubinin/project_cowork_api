<?php

namespace App\Controller\Api;

use App\DTO\ResourceListFilterInput;
use App\Repository\ResourceRepository;
use App\Service\ResourceAvailabilityService;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

final class ResourceClientController extends AbstractController
{
    #[Route('/api/resources', name: 'api_client_resources', methods: ['GET'])]
    public function index(
        ResourceRepository $repository,
        PaginatorInterface $paginator,
        ResourceAvailabilityService $resourcesAvailable,
        SerializerInterface $serializer,
        #[MapQueryString(validationFailedStatusCode: 400)] ?ResourceListFilterInput $filters = null
    ): JsonResponse
    {
        $filters ??= new ResourceListFilterInput();

        $resources = $repository->findListForClientByFilters($filters);
        $nearestSlots = $resourcesAvailable->findNearestIntervals($resources, $filters);
        $responseData = $resourcesAvailable->generateResponseData($serializer, $resources, $nearestSlots);

        $page = $filters->page ?? 1;
        $limit = $filters->limit ?? 10;
        $responseDataPagination = $paginator->paginate($responseData, $page, $limit);

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
