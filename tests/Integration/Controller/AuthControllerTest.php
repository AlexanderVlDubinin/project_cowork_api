<?php

namespace App\Tests\Integration\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AuthControllerTest extends WebTestCase
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
     * Testing authentication
     */
    public function testLoginSuccess(): void
    {
        $this->client->request(
            'POST',
            '/api/login_check',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'username' => 'client@example.com',
                'password' => 'password123'
            ])
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('token', $data); // LexikBundle must return the token
    }

    /**
     * Testing a login attempt with incorrect credentials
     */
    public function testLoginInvalidCredentials(): void
    {
        $this->client->request(
            'POST',
            '/api/login_check',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
            'username' => 'wrong@example.com',
            'password' => 'bad_password'
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * User registration testing - endpoint /api/register
     */
    public function testRegisterSuccess(): void
    {
        $email = 'newUser_' . uniqid() . '@example.com';

        $this->client->request(
            'POST',
            '/api/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'fullName' => 'John Doe',
            'email' => $email,
            'password' => 'qwerty123456'
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString($email, $responseData['email']);
        $this->assertStringContainsString('User registered successfully', $responseData['message']);
    }
}
