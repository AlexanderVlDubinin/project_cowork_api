<?php

namespace App\Tests\Unit\Service;

use App\DTO\RegistrationInput;
use App\Entity\User;
use App\Service\RegistrationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class RegistrationServiceTest extends TestCase
{
    private EntityManagerInterface $entityManagerMock;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        $this->entityManagerMock = $this->createMock(EntityManagerInterface::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
    }

    public function testRegisterCreatesUserWithHashedPassword(): void
    {
        // Configuring the behavior of the password hasher
        $this->passwordHasher->expects($this->once())
            ->method('hashPassword')
            ->with($this->isInstanceOf(User::class), 'plain_password')
            ->willReturn('hashed_secure_password');

        // Checking that the EntityManager saves the data to the database
        $this->entityManagerMock->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(User::class));
        $this->entityManagerMock->expects($this->once())
            ->method('flush');

        // Initializing the service and DTO
        $service = new RegistrationService($this->entityManagerMock, $this->passwordHasher);

        $input = new RegistrationInput();
        $input->fullName = 'Test Name';
        $input->email = 'test@example.com';
        $input->password = 'plain_password';

        // Performing the operation
        $user = $service->register($input);

        // Verifications (Assertions)
        $this->assertSame('Test Name', $user->getFullName());
        $this->assertSame('test@example.com', $user->getEmail());
        $this->assertSame('hashed_secure_password', $user->getPassword());
        $this->assertContains(User::ROLE_USER, $user->getRoles());
    }
}
