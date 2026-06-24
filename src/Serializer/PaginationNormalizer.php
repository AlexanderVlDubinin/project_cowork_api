<?php

namespace App\Serializer;

use Knp\Bundle\PaginatorBundle\Pagination\SlidingPagination;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class PaginationNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator
    ) {}

    public function normalize($data, ?string $format = null, array $context = []): array
    {
        /** @var SlidingPagination $data */
        $page = $data->getCurrentPageNumber();
        $limit = $data->getItemNumberPerPage();
        $totalItems = $data->getTotalItemCount();
        $totalPages = (int) ceil($totalItems / $limit);

        // Generating a link to the previous page
        $prevPageUrl = null;
        if ($page > 1) {
            $prevPageUrl = $this->urlGenerator->generate(
                $data->getRoute(),
                array_merge($data->getParams(), [
                    'page' => $page - 1,
                    'limit' => $limit
                ])
            );
        }

        // Generating a link to the next page
        $nextPageUrl = null;
        if ($page < $totalPages) {
            $nextPageUrl = $this->urlGenerator->generate(
                $data->getRoute(),
                array_merge($data->getParams(), [
                    'page' => $page + 1,
                    'limit' => $limit
                ])
            );
        }

        // Normalizing the elements inside the pagination
        $output = [];
        foreach ($data->getItems() as $item) {
            $output[] = $this->normalizer->normalize($item, $format, $context);
        }

        return [
            'data' => $output,
            'meta' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'prev_page_url' => $prevPageUrl,
                'next_page_url' => $nextPageUrl
            ]
        ];
    }

    public function supportsNormalization($data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof SlidingPagination;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            SlidingPagination::class => true,
        ];
    }
}
