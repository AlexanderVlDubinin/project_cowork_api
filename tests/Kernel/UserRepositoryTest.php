<?php

namespace App\Tests\Kernel;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->clearDatabase();
    }

    private function clearDatabase(): void
    {
        // Clearing the tables before the test
        foreach ($this->em->getRepository(User::class)->findAll() as $u) {
            $this->em->remove($u);
        }
        $this->em->flush();

        // test admin
        $user = new User();
        $user->setFullName('Test Admin 1');
        $user->setEmail('admin@example.com');
        $hashedPassword = $this->passwordHasher->hashPassword($user, 'password123');
        $user->setPassword($hashedPassword);
        $user->setRoles(['ROLE_ADMIN']);
        $this->em->persist($user);

        // test user
        $user = new User();
        $user->setFullName('Test User 1');
        $user->setEmail('client@example.com');
        $hashedPassword = $this->passwordHasher->hashPassword($user, 'password123');
        $user->setPassword($hashedPassword);
        $user->setRoles(['ROLE_USER']);
        $this->em->persist($user);

        $this->em->flush();
    }

    /**
     * 1. The tested method (findOnlyRegularUsers) finds only regular users (not admins).
     */
    public function testFindOnlyRegularUsersExcludesAdmins(): void
    {
        // Calling the method under test
        $regularUsers = $this->em->getRepository(User::class)->findOnlyRegularUsers();

        // Checking that only users without admin roles are included in the selection.
        foreach ($regularUsers as $user) {
            $this->assertInstanceOf(User::class, $user);
            $this->assertNotContains(User::ROLE_ADMIN, $user->getRoles());
            $this->assertNotContains(User::ROLE_SUPER_ADMIN, $user->getRoles());
        }
    }
}
