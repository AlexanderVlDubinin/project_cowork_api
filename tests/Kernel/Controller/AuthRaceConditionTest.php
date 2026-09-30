<?php

namespace App\Tests\Kernel\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Response;

class AuthRaceConditionTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get('doctrine.orm.entity_manager');

        $this->clearDatabase();
    }

    private function clearDatabase(): void
    {
        // Clearing the tables before the test
        foreach ($this->em->getRepository(User::class)->findAll() as $u) {
            $this->em->remove($u);
        }
        $this->em->flush();
    }

    /**
     * Tests the registration endpoint for a race condition.
     */
    public function testRegisterRaceCondition(): void
    {
        // 1. Using HttpClient because it supports parallel asynchronous requests.
        // The URL of the local Symfony test server
        $client = HttpClient::create();
        $baseUrl = 'http://nginx/api/register';

        // Generating one common email for the "race"
        $duplicateEmail = 'race_condition_' . uniqid() . '@example.com';

        $payload = [
            'fullName' => 'Race Tester',
            'email' => $duplicateEmail,
            'password' => 'SecurePass123!'
        ];

        // Number of simultaneous requests
        $numberOfRequests = 3;
        $responses = [];

        // 2. Initiate requests (they are sent non-blocking/parallel)
        for ($i = 0; $i < $numberOfRequests; $i++) {
            $responses[] = $client->request('POST', $baseUrl, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                ]
            ]);
        }

        // Variables for calculating race results
        $createdCount = 0;
        $conflictCount = 0;
        $otherCount = 0;

        // 3. Reading the answers as they are completed.
        foreach ($responses as $response) {
            try {
                // Trying to get the status code (here PHP will wait for a response from the server)
                $statusCode = $response->getStatusCode();

                if ($statusCode === Response::HTTP_CREATED) {
                    $createdCount++;
                } elseif ($statusCode === Response::HTTP_CONFLICT) {
                    $conflictCount++;
                } else {
                    $otherCount++;
                }
            } catch (\Exception $e) {
                $this->fail('Error when executing a parallel request: ' . $e->getMessage());
            }
        }

        // 4. Checking the business logic of the Race Condition
        // Exactly ONE user must be successfully created.
        $this->assertSame(1, $createdCount, 'Exactly one user must be created.');

        // All other requests (N - 1) should fall into the 409 Conflict.
        $this->assertSame($numberOfRequests - 1, $conflictCount, 'The rest of the requests should return 409 Conflict.');

        // There should be no other errors (500 Internal Server Error).
        $this->assertSame(0, $otherCount, 'There should be no unexpected errors (e.g. 500).');
    }
}
