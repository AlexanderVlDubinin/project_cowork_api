<?php

namespace App\Controller\Api\Admin;

use App\DTO\ResourceInput;
use App\DTO\ResourceListFilterInput;
use App\Entity\Resource;
use App\Repository\ResourceRepository;
use App\Service\ResourceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin')]
final class ResourceAdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ResourceService $resourceService
    ) {}

    /**
     * GET /api/admin/resources
     */
    #[Route('/resources', name: 'api_admin_resources', methods: ['GET'])]
    public function index(
        ResourceRepository $repository,
        #[MapQueryString(validationFailedStatusCode: 400)] ?ResourceListFilterInput $filters = null
    ): JsonResponse
    {
        $filters ??= new ResourceListFilterInput();
        $resources = $repository->findListForAdminByFilters($filters);

        return $this->json(
            $resources,
            Response::HTTP_OK,
            [],
            [
                'groups' => ['resource:read']
            ]
        );
    }

    /**
     * POST /api/admin/resource
     */
    #[Route('/resource', name: 'api_admin_resource_create', methods: ['POST'])]
    public function create(
        #[MapRequestPayload(validationGroups: ['Default', 'create'])] ResourceInput $input
    ): JsonResponse {
        $resource = new Resource();

        $this->resourceService->mapInputToEntity($input, $resource);

        $this->entityManager->persist($resource);
        $this->entityManager->flush();

        return $this->json($resource,
            Response::HTTP_CREATED,
            [],
            [
                'groups' => ['resource:read']
            ]
        );
    }

    /**
     * GET /api/admin/resource/{id}
     * The {id} pattern checks that the correct UUID is passed
     */
    #[Route('/resource/{id}', name: 'api_admin_resource_show', requirements: ['id' => '[a-fA-F0-9-]{36}'], methods: ['GET'])]
    public function show(Resource $resource): JsonResponse
    {
        return $this->json(
            $resource,
            Response::HTTP_OK,
            [],
            [
                'groups' => ['resource:read']
            ]
        );
    }

    /**
     * PUT /api/admin/resource/{id}
     */
    #[Route('/resource/{id}', name: 'api_admin_resource_update', requirements: ['id' => '[a-fA-F0-9-]{36}'], methods: ['PUT'])]
    public function update(
        Resource $resource,
        #[MapRequestPayload] ResourceInput $input
    ): JsonResponse {
        $this->resourceService->mapInputToEntity($input, $resource);

        $this->entityManager->flush();

        return $this->json(
            $resource,
            Response::HTTP_OK,
            [],
            [
                'groups' => ['resource:read']
            ]
        );
    }

    /**
     * DELETE /api/admin/resource/{id}
     */
    #[Route('/resource/{id}', name: 'api_admin_resource_delete', requirements: ['id' => '[a-fA-F0-9-]{36}'], methods: ['DELETE'])]
    public function delete(Resource $resource): JsonResponse
    {
        $this->entityManager->remove($resource);
        $this->entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
