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

class ResourceAdminControllerTest extends WebTestCase
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
        $existingUser = $this->em->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);
        if ($existingUser) {
            $this->em->remove($existingUser);
        }
        // Cleaning up old test resources to avoid overgrowth of the database
        $existingResources = $this->em->getRepository(Resource::class)->findAll();
        foreach ($existingResources as $res) {
            $this->em->remove($res);
        }
        $this->em->flush();

        // test admin
        $user = new User();
        $user->setFullName('Test Admin 1');
        $user->setEmail('admin@example.com');
        //$user->setPassword('password123'); // use PasswordHasher
        $hashedPassword = $this->passwordHasher->hashPassword($user, 'password123');
        $user->setPassword($hashedPassword);
        $user->setRoles(['ROLE_ADMIN']);
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

    private function logInAsAdmin(): void
    {
        $userRepository = $this->em->getRepository(User::class);
        $testUser = $userRepository->findOneBy(['email' => 'admin@example.com']);

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
        $this->logInAsAdmin();

        $this->client->request(
            'GET',
            '/api/admin/resources',
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

    public function testGettingResourcesListWithWrongParameter(): void
    {
        $this->logInAsAdmin();

        $this->client->request(
            'GET',
            '/api/resources',
            [
                'startDate' => '2027-01-01',
            ],
            [],
            $this->getAuthHeaders(),
        );

        // DTO validation error
        $this->assertEquals(422, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $responseData);
        $this->assertArrayHasKey('startDate', $responseData['errors']);
        $this->assertStringContainsString(
            'Start date must be in ATOM format (ISO 8601)',
            $responseData['errors']['startDate']
        );
    }

    public function testCreatingResourceAndSameTitleConflict(): void
    {
        $this->logInAsAdmin();

        $title = 'Meeting room # 1';
        $this->client->request(
            'POST',
            '/api/admin/resource',
            [],
            [],
            $this->getAuthHeaders(),
            json_encode([
                'title' => $title,
                'type' => ResourceType::MEETING_ROOM,
                'description' => 'Meeting room # 1 description.',
                'isActive' => true,
                'pricePerHour' => 1200
            ])
        );

        $this->assertEquals(201, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $responseData);
        $this->assertArrayHasKey('title', $responseData);
        $this->assertStringContainsString(
            $title,
            $responseData['title']
        );
        $this->assertTrue($responseData['isActive']);

        $this->client->request(
            'POST',
            '/api/admin/resource',
            [],
            [],
            $this->getAuthHeaders(),
            json_encode([
                'title' => $title,
                'type' => ResourceType::MEETING_ROOM,
                'description' => 'Meeting room # 1 description.',
                'isActive' => true,
                'pricePerHour' => 1200
            ])
        );

        // DTO validation error
        $this->assertEquals(422, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $responseData);
        $this->assertArrayHasKey('title', $responseData['errors']);
        $this->assertStringContainsString(
            'A desk/room with that name already exists',
            $responseData['errors']['title']
        );
    }

    public function testCreatingResourceWithWrongParameter(): void
    {
        $this->logInAsAdmin();

        $title = 'Meeting room # 1';
        $this->client->request(
            'POST',
            '/api/admin/resource',
            [],
            [],
            $this->getAuthHeaders(),
            json_encode([
                'title' => $title,
                'type' => 'qwerty', // ResourceType::MEETING_ROOM
                'description' => 'Meeting room # 1 description.',
                'isActive' => true,
                'pricePerHour' => 1200
            ])
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

    public function testUpdatingResource(): void
    {
        $this->logInAsAdmin();

        $title = 'Test Desk № 12';
        $resourceRepository = $this->em->getRepository(Resource::class);
        $testResource = $resourceRepository->findOneBy(['title' => $title]);

        $newTitle = 'Test Meeting room № 12';
        $this->client->request(
            'PUT',
            '/api/admin/resource/'.$testResource->getId()->toString(),
            [],
            [],
            $this->getAuthHeaders(),
            json_encode([
                'title' => $newTitle,
                'type' => ResourceType::MEETING_ROOM,
                'description' => 'Meeting room # 12 description.',
                'isActive' => true,
                'pricePerHour' => 1200
            ])
        );

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $responseData);
        $this->assertEquals($testResource->getId()->toString(), $responseData['id']);
        $this->assertArrayHasKey('title', $responseData);
        $this->assertStringContainsString(
            $newTitle,
            $responseData['title']
        );
    }

    public function testDeletingResource(): void
    {
        $this->logInAsAdmin();

        $title = 'Test Desk № 12';
        $resourceRepository = $this->em->getRepository(Resource::class);
        $testResource = $resourceRepository->findOneBy(['title' => $title]);

        $resourceId = $testResource->getId()->toString();
        $this->client->request(
            'DELETE',
            '/api/admin/resource/'.$resourceId,
            [],
            [],
            $this->getAuthHeaders(),
        );

        $this->assertEquals(204, $this->client->getResponse()->getStatusCode());

        $testResource = $resourceRepository->findOneBy(['id' => $resourceId]);
        $this->assertNull($testResource);
    }
}
