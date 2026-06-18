<?php

namespace App\DTO;

use App\Entity\Resource;
use App\Enum\ResourceType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[UniqueEntity(
    fields: ['title'],
    message: 'A desk/room with that name already exists',
    entityClass: Resource::class,
    groups: ['create']
)]
class ResourceInput
{
    #[Assert\NotBlank(message: "A desk/room title must not be empty.")]
    #[Assert\Length(
        min: 2,
        max: 255,
        minMessage: "A desk/room title must be at least 2 characters long.",
        maxMessage: "A desk/room title must be no more than 255 characters long."
    )]
    public string $title;

    #[Assert\NotBlank(message: "Type must not be empty.")]
    #[Assert\Choice(callback: [ResourceType::class, 'cases'], message: 'Invalid resource type.')]
    public ResourceType $type;

    public ?string $description = null;

    public bool $isActive = true;

    #[Assert\NotBlank(message: "The price must be specified.")]
    #[Assert\Positive(message: "The price must be a positive number.")]
    public int $pricePerHour;
}
