<?php

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\Resource;
use App\Entity\User;
use App\Enum\ResourceType;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ResourceAdminControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
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
        $existingUsers = $this->em->getRepository(User::class)->findAll();
        foreach ($existingUsers as $existingUser) {
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

        // test user
        $user = new User();
        $user->setFullName('Test User 1');
        $user->setEmail('client@example.com');
        $hashedPassword = $this->passwordHasher->hashPassword($user, 'password123');
        $user->setPassword($hashedPassword);
        $user->setRoles(['ROLE_USER']);
        $this->em->persist($user);

        for ($i = 1; $i <= 14; $i++) { // 14 resources, 2 meeting rooms and 12 desks
            // test resource
            $testResource = new Resource();
            $title = $i >= 13 ? 'Meeting Room № '.$i : 'Test Desk № '.$i;
            $testResource->setTitle($title);
            $type = $i >= 13 ? ResourceType::MEETING_ROOM : ResourceType::DESK;
            $testResource->setType($type);
            $testResource->setDescription($title.' Description');
            $testResource->setIsActive(true);
            $price = $i >= 13 ? 1200 : 500;
            $testResource->setPricePerHour($price);
            $this->em->persist($testResource);
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

    public function testUnauthorizedUserRequestReturns401(): void
    {
        $this->client->request('GET', '/api/admin/resources');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRegularUserRequestReturns403()
    {
        $this->logInAsClient();

        $this->client->request(
            'GET',
            '/api/admin/resources',
            [],
            [],
            $this->getAuthHeaders(),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
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

        $this->assertCount(14, $testResources);
    }

    public function testGettingResourcesListWithTypeParameterSuccess(): void
    {
        $this->logInAsAdmin();

        $this->client->request(
            'GET',
            '/api/admin/resources',
            [
                'type' => ResourceType::DESK->value,
            ],
            [],
            $this->getAuthHeaders(),
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $responseData);
        $this->assertCount(10, $responseData['data']);

        $resourceRepository = $this->em->getRepository(Resource::class);
        $testResourcesDesk = $resourceRepository->findBy(['type' => ResourceType::DESK->value]);
        $testResourcesAll = $resourceRepository->findAll();

        $this->assertCount(12, $testResourcesDesk);
        $this->assertCount(14, $testResourcesAll);
    }

    public function testGettingResourcesListWithQuerySearchSuccess(): void
    {
        $this->logInAsAdmin();

        $this->client->request(
            'GET',
            '/api/admin/resources',
            [
                'query' => 'Meeting',
            ],
            [],
            $this->getAuthHeaders(),
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $responseData);
        $this->assertCount(2, $responseData['data']);
    }

    public function testGettingResourcesListForSecondPageSuccess(): void
    {
        $this->logInAsAdmin();

        $this->client->request(
            'GET',
            '/api/admin/resources',
            [
                'page' => 2,
                'limit' => 10,
            ],
            [],
            $this->getAuthHeaders(),
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $responseData);
        $this->assertCount(4, $responseData['data']);

        $resourceRepository = $this->em->getRepository(Resource::class);
        $testResources = $resourceRepository->findAll();

        $this->assertCount(14, $testResources);
    }

    public function testGettingResourcesListWithWrongParameter(): void
    {
        $this->logInAsAdmin();

        // wrong type
        $this->client->request(
            'GET',
            '/api/admin/resources',
            [
                'type' => 'desk1',
            ],
            [],
            $this->getAuthHeaders(),
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

        // wrong query - too short
        $this->client->request(
            'GET',
            '/api/admin/resources',
            [
                'query' => 'te',
            ],
            [],
            $this->getAuthHeaders(),
        );

        // DTO validation error
        $this->assertEquals(422, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $responseData);
        $this->assertArrayHasKey('query', $responseData['errors']);
        $this->assertStringContainsString(
            'Query must be at least 3 characters long',
            $responseData['errors']['query']
        );

        // wrong query - too long
        $sentence = 'A eos cupiditate natus facilis facilis optio nisi officia. Architecto omnis assumenda ipsam laboriosam tempore voluptatem pariatur. ';
        // 115 characters, 3 times
        $longQuery = str_repeat($sentence, 3);
        $this->client->request(
            'GET',
            '/api/admin/resources',
            [
                'query' => $longQuery,
            ],
            [],
            $this->getAuthHeaders(),
        );

        // DTO validation error
        $this->assertEquals(422, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $responseData);
        $this->assertArrayHasKey('query', $responseData['errors']);
        $this->assertStringContainsString(
            'Query must be at most 255 characters long',
            $responseData['errors']['query']
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

    public function testUpdatingResourceWithExistingNameFails(): void
    {
        $this->logInAsAdmin();

        $title = 'Test Desk № 12';
        $resourceRepository = $this->em->getRepository(Resource::class);
        $testResource = $resourceRepository->findOneBy(['title' => $title]);

        $newTitle = 'Test Desk № 1';
        $this->client->request(
            'PUT',
            '/api/admin/resource/'.$testResource->getId()->toString(),
            [],
            [],
            $this->getAuthHeaders(),
            json_encode([
                'title' => $newTitle,
                'type' => ResourceType::DESK,
                'description' => 'Test Desk № 1 description.',
                'isActive' => true,
                'pricePerHour' => 500
            ])
        );

        // there should be a 409 HTTP_CONFLICT status
        $this->assertEquals(409, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('title', $responseData);
        $this->assertArrayHasKey('status', $responseData);
        $this->assertArrayHasKey('detail', $responseData);
        $this->assertEquals(409, $responseData['status']);
        $this->assertEquals('A desk/room with that name already exists', $responseData['detail']);
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

    public function testShowingResourceNotFoundReturnsJson(): void
    {
        $this->logInAsAdmin();

        // Valid UUID (36 characters), which is definitely not in the database
        $nonExistentUuid = '00000000-0000-0000-0000-000000000000';

        $this->client->request(
            'GET',
            '/api/admin/resource/' . $nonExistentUuid,
            [],
            [],
            $this->getAuthHeaders()
        );

        $this->assertEquals(404, $this->client->getResponse()->getStatusCode());

        // Make sure that the JSON format is specified in the response header.
        $contentType = $this->client->getResponse()->headers->get('Content-Type');
        $this->assertStringContainsString('json', $contentType);

        // Checking that the response body is successfully parsed as JSON.
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($responseData);

        // Checking the Symfony Standard Error structure (RFC 7807 Problem Details)
        $this->assertArrayHasKey('title', $responseData);
        $this->assertArrayHasKey('status', $responseData);
        $this->assertEquals(404, $responseData['status']);
    }


    // TODO: Add a test for whether a resource has an active booking
}
