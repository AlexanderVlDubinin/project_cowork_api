<?php

namespace App\DTO;

use App\Entity\User;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[UniqueEntity(fields: ['email'], message: 'This email is already registered', entityClass: User::class)]
class RegistrationInput
{
    #[Assert\NotBlank(message: "Full name must not be empty.")]
    #[Assert\Length(
        min: 2,
        max: 255,
        minMessage: "Full name must be at least 2 characters long.",
        maxMessage: "Full name must be no more than 255 characters long."
    )]
    public string $fullName;

    #[Assert\NotBlank(message: "Email must not be empty.")]
    #[Assert\Email(message: "Incorrect email format")]
    public string $email;

    #[Assert\NotBlank(message: "Password must not be empty.")]
    #[Assert\Length(min: 8, minMessage: "Password must be at least 8 characters long.")]
    public string $password;
}
