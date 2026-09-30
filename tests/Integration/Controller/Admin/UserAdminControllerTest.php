<?php

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserAdminControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        // Cleaning/preparing the environment before each test
        $this->createTestData();
    }

    private function createTestData(): void
    {
        // DB clear
        // Clearing old users to avoid overgrowth of the database
        $existingUsers = $this->em->getRepository(User::class)->findAll();
        foreach ($existingUsers as $existingUser) {
            $this->em->remove($existingUser);
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
     * 1. When an anonymous user tries to log in to a page with a list of users, a 401 error is returned.
     */
    public function testAdminUsersIsSecuredForAnonymous(): void
    {
        $this->client->request('GET', '/api/admin/users');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * 2. When a regular user tries to log in to a page with a list of users, a 403 Forbidden error is returned.
     */
    public function testAdminUsersIsForbiddenForRegularUser(): void
    {
        $regularUser = $this->em->getRepository(User::class)->findOneBy(['email' => 'client@example.com']);

        $this->client->loginUser($regularUser, 'api'); // authenticating in the firewall 'api'
        $this->client->request('GET', '/api/admin/users');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * 3. When an admin user tries to log in to a page with a list of users, a 200 OK response is returned.
     */
    public function testAdminUsersIsAccessibleByAdmin(): void
    {
        $adminUser = $this->em->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);

        $this->client->loginUser($adminUser, 'api');
        $this->client->request('GET', '/api/admin/users');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // Checking the pagination structure
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);
    }
}
