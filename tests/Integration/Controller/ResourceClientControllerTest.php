<?php

namespace App\Tests\Integration\Controller;

use App\Entity\Resource;
use App\Entity\User;
use App\Enum\ResourceType;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ResourceClientControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Resource $testResource;
    private UserPasswordHasherInterface $passwordHasher;
    private string $jwtToken;

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
        // Clearing old users with the same email address
        $existingUser = $this->em->getRepository(User::class)->findOneBy(['email' => 'client@example.com']);
        if ($existingUser) {
            $this->em->remove($existingUser);
        }
        // Cleaning up old test resources to avoid overgrowth of the database
        $existingResources = $this->em->getRepository(Resource::class)->findAll();
        foreach ($existingResources as $res) {
            $this->em->remove($res);
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

        for ($i = 1; $i <= 12; $i++) {
            // test resource
            $this->testResource = new Resource();
            $this->testResource->setTitle('Test Desk № '.$i);
            $this->testResource->setType(ResourceType::DESK);
            $this->testResource->setDescription('Test Desk № '.$i.' Description');
            $this->testResource->setIsActive(true);
            $this->testResource->setPricePerHour(500);
            $this->em->persist($this->testResource);
        }

        $this->em->flush();
    }

    private function logInAsClient(): void
    {
        $userRepository = $this->em->getRepository(User::class);
        $testUser = $userRepository->findOneBy(['email' => 'client@example.com']);

        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);
        $this->jwtToken = $jwtManager->create($testUser);
    }

    private function getAuthHeaders(): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtToken,
        ];
    }

    public function testGettingResourcesListSuccess(): void
    {
        $this->logInAsClient();

        $this->client->request(
            'GET',
            '/api/resources',
            [],
            [],
            $this->getAuthHeaders(),
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $responseData);
        $this->assertCount(10, $responseData['data']);

        $resourceRepository = $this->em->getRepository(Resource::class);
        $testResources = $resourceRepository->findAll();

        $this->assertCount(12, $testResources);
    }

    public function testGettingBookingsListWithWrongParameter(): void
    {
        $this->logInAsClient();

        $this->client->request(
            'GET',
            '/api/resources',
            [
                'type' => 'desk1',
            ],
            [],
            $this->getAuthHeaders(), // JWT
        );

        // DTO validation error
        $this->assertEquals(422, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $responseData);
        $this->assertArrayHasKey('type', $responseData['errors']);
        $this->assertStringContainsString(
            'Invalid resource type. Available options: desk, meeting_room',
            $responseData['errors']['type']
        );
    }
}
