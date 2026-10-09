<?php

namespace App\Tests\Integration\Controller;

use App\Entity\Booking;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\ResourceType;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ResourceClientControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $passwordHasher;
    private \DateTimeImmutable $baseTime;
    private string $jwtToken;
    private int $bookingTechBreak = 5; // A stub for tech break

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        // Fixing the "now" time for Monday of the following week at 12:00 p.m.
        $this->baseTime = new \DateTimeImmutable('next week monday 12:00:00', new \DateTimeZone('UTC'));
        self::mockTime($this->baseTime);

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

        $user = new User();
        $user->setFullName('Test User 2');
        $user->setEmail('client2@example.com');
        $hashedPassword = $this->passwordHasher->hashPassword($user, 'password123');
        $user->setPassword($hashedPassword);
        $user->setRoles(['ROLE_USER']);
        $this->em->persist($user);

        for ($i = 1; $i <= 12; $i++) {
            // test resource
            $testResource = new Resource();
            $testResource->setTitle('Test Desk № '.$i);
            $testResource->setType(ResourceType::DESK);
            $testResource->setDescription('Test Desk № '.$i.' Description');
            $testResource->setIsActive(true);
            $testResource->setPricePerHour(500);
            $this->em->persist($testResource);
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

    /**
     * HELPER: Adjusts the date to account for the booking technical break
     *
     * @param \DateTimeImmutable $date
     * @param string $dateType start or end
     * @return \DateTimeImmutable
     */
    private function getDbDate(\DateTimeImmutable $date, string $dateType): \DateTimeImmutable
    {
        $dateDb = $date;
        if ($dateType === 'start') {
            if ($date->format('H:i') !== '08:00') {
                $dateDb = $date->modify("-{$this->bookingTechBreak} minutes");
            }
        }
        if ($dateType === 'end') {
            if ($date->format('H:i') !== '20:00') {
                $dateDb = $date->modify("+{$this->bookingTechBreak} minutes");
            }
        }

        return $dateDb;
    }

    /**
     * Test getting resources list success
     */
    public function testGettingResourcesListSuccess(): void
    {
        $this->logInAsClient();

        $this->client->request(
            'GET',
            '/api/resources',
            [
                'startDate' => $this->baseTime->format(\DateTimeInterface::ATOM),
            ],
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

    /**
     * Test getting bookings list with wrong parameter returns 422
     */
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

    /**
     * Automatic shift test over the weekend, when requesting the start of booking for Friday evening
     */
    public function testFridayEveningRequestAutoshiftsToMondayMorning(): void
    {
        $this->logInAsClient();

        // Setting the base time for Friday at 19:45:00
        // There are 15 minutes left before closing (less than 30 of the first day's limit).
        $fridayEvening = $this->baseTime->modify('+4 days')->setTime(19, 45, 0);
        self::mockTime($fridayEvening);

        $this->client->request(
            'GET',
            '/api/resources',
            [
                'startDate' => $fridayEvening->format(\DateTimeInterface::ATOM),
                //'duration' => 30 // default duration
            ],
            [],
            $this->getAuthHeaders()
        );

        $this->assertResponseIsSuccessful();
        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        // Expecting the start on Monday (after the weekend) at 08:00
        $expectedStart = $fridayEvening->modify('+3 days')->setTime(8, 0, 0)->format(\DateTimeInterface::ATOM);
        $this->assertEquals($expectedStart, $responseData['data'][0]['nearest_slot']['start']);
    }

    /**
     * When requesting the start of a booking for the evening
     * and specifying the end in the early morning of the next day (before 08:00),
     * it should return a 422 error due to the start being shifted to 08:00
     * (start later than the end)
     */
    public function testEveningRequestWithTightEndDateReturnsValidationError(): void
    {
        $this->logInAsClient();

        // Monday 19:45
        $start = $this->baseTime->setTime(19, 45, 0);
        // Tuesday 02:00 (client's hard limits)
        $end = $this->baseTime->modify('+1 day')->setTime(2, 0, 0);

        $this->client->request(
            'GET',
            '/api/resources',
            [
                'startDate' => $start->format(\DateTimeInterface::ATOM),
                'endDate' => $end->format(\DateTimeInterface::ATOM),
                'duration' => 30
            ],
            [],
            $this->getAuthHeaders()
        );

        // DTO validation error
        $this->assertEquals(422, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $responseData);
        $errorMessage = 'After shifting the start time (to the beginning of the working day - 08:00) due to insufficient time on the first day (<30 mins), the start time moved past or equal to the end time.';
        $this->assertStringContainsString($errorMessage, $responseData['errors']['startDate']);
    }

    /**
     * Test of imposing a technical break - that the technical break is not ignored and is calculated correctly.
     */
    public function testOfImposingTechBreak(): void
    {
        $this->logInAsClient();

        // 1. Using a test resource (a place in a coworking space).
        $title = 'Test Desk № 1';
        $resourceRepository = $this->em->getRepository(Resource::class);
        $testResource = $resourceRepository->findOneBy(['title' => $title]);

        // 2. Clearing the database of other resources.
        $resourceRepository = $this->em->getRepository(Resource::class);
        $testResources = $resourceRepository->findAll();
        foreach ($testResources as $resource) {
            if ($resource->getTitle() !== $title)
            $this->em->remove($resource);
        }

        // Using a test user to link an existing booking.
        $testEmail = 'client@example.com';
        $userRepository = $this->em->getRepository(User::class);
        $testUser = $userRepository->findOneBy(['email' => $testEmail]);


        // test booking 12:00 - 13:00 (+ 5 minutes of tech break)
        $startDate = $this->baseTime;
        $startDateDb = $this->getDbDate($startDate, 'start');
        $endDate = $this->baseTime->modify('+1 hour');
        $endDateDb = $this->getDbDate($endDate, 'end');

        $existingBooking = new Booking();
        $existingBooking
            ->setUser($testUser)
            ->setResource($testResource)
            ->setStartedAt($this->baseTime) // 12:00
            ->setEndedAt($this->baseTime->modify('+1 hour')) // 13:00
            ->setDbBlockedFrom($startDateDb)
            ->setDbBlockedTo($endDateDb)
            ->setTotalPrice(500)
            ->setStatus(BookingStatus::PENDING);
        $this->em->persist($existingBooking);

        $this->em->flush();

        // 3. Simulating the client's request: Monday 12:30 p.m. Duration = 60 minutes.
        $newBookingTime = $this->baseTime->setTime(12, 30, 0);
        $this->client->request(
            'GET',
            '/api/resources',
            [
                'startDate' => $newBookingTime->format(\DateTimeInterface::ATOM),
                'duration' => 60
            ],
            [],
            $this->getAuthHeaders()
        );

        // Validation must be successful (status 200)
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        // Checking that the response contains pagination data and a test resource.
        $this->assertArrayHasKey('data', $responseData);
        $this->assertCount(1, $responseData['data']);

        $targetResourceData = $responseData['data'][0];
        $this->assertEquals('Test Desk № 1', $targetResourceData['title']);

        // 4. CHECKING THE ACCOUNTING OF A TECHNICAL BREAK:
        // A slot must be found from 13:05 to 14:05 - after an existing booking and a technical break.
        $expectedStart = $this->baseTime->setTime(13, 05, 0)->format(\DateTimeInterface::ATOM);
        $expectedEnd = $this->baseTime->setTime(14, 05, 0)->format(\DateTimeInterface::ATOM);

        $this->assertEquals($expectedStart, $targetResourceData['nearest_slot']['start']);
        $this->assertEquals($expectedEnd, $targetResourceData['nearest_slot']['end']);
    }

    /**
     * Checking both the evening shift and the technical break.
     */
    public function testEveningRequestAutoshiftsToNextMorningWithTechBreak(): void
    {
        $this->logInAsClient();

        // 1. Using a test resource (a place in a coworking space).
        $title = 'Test Desk № 1';
        $resourceRepository = $this->em->getRepository(Resource::class);
        $testResource = $resourceRepository->findOneBy(['title' => $title]);

        // 2. Clearing the database of other resources.
        $resourceRepository = $this->em->getRepository(Resource::class);
        $testResources = $resourceRepository->findAll();
        foreach ($testResources as $resource) {
            if ($resource->getTitle() !== $title)
                $this->em->remove($resource);
        }

        // Using a test user to link an existing booking.
        $testEmail = 'client@example.com';
        $userRepository = $this->em->getRepository(User::class);
        $testUser = $userRepository->findOneBy(['email' => $testEmail]);


        // test booking 08:00 - 08:30 (+ 5 minutes of tech break)
        $startDate = $this->baseTime->modify('+1 day')->setTime(8, 0, 0); // Monday 08:00
        $startDateDb = $this->getDbDate($startDate, 'start');
        $endDate = $this->baseTime->modify('+1 day')->setTime(8, 30, 0); // Monday 08:30
        $endDateDb = $this->getDbDate($endDate, 'end');

        $existingBooking = new Booking();
        $existingBooking
            ->setUser($testUser)
            ->setResource($testResource)
            ->setStartedAt($startDate)
            ->setEndedAt($endDate)
            ->setDbBlockedFrom($startDateDb)
            ->setDbBlockedTo($endDateDb)
            ->setTotalPrice(500)
            ->setStatus(BookingStatus::CONFIRMED);
        $this->em->persist($existingBooking);

        $this->em->flush();

        // 3. Simulating the client's request: Monday 19:45, Duration = 30 minutes.
        // There are 15 minutes left before closing (less than 30 of the first day's limit).
        $mondayEvening = $this->baseTime->setTime(19, 45, 0)->format(\DateTimeInterface::ATOM);

        $this->client->request(
            'GET',
            '/api/resources',
            [
                'startDate' => $mondayEvening,
                //'duration' => 30 // default duration
            ],
            [],
            $this->getAuthHeaders()
        );

        // Validation must be successful (status 200)
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        // Checking that the response contains pagination data and a test resource.
        $this->assertArrayHasKey('data', $responseData);
        $this->assertCount(1, $responseData['data']);

        $targetResourceData = $responseData['data'][0];
        $this->assertEquals('Test Desk № 1', $targetResourceData['title']);

        // 4. CHECKING FOR AUTO-TRANSFER AND TECHNICAL BREAK:
        // The slot should be moved from Monday evening to Tuesday morning.
        // Since the 08:00-08:30 period is busy, and the technical break is 5 minutes,
        // the nearest free slot should start at exactly 08:35 and last 30 minutes (until 09:05).
        $expectedStart = $this->baseTime
            ->modify('+1 day')
            ->setTime(8, 35, 0)
            ->format(\DateTimeInterface::ATOM);
        $expectedEnd = $this->baseTime
            ->modify('+1 day')
            ->setTime(9, 5, 0)
            ->format(\DateTimeInterface::ATOM);

        $this->assertEquals($expectedStart, $targetResourceData['nearest_slot']['start']);
        $this->assertEquals($expectedEnd, $targetResourceData['nearest_slot']['end']);

        // Checking the correctness of the price calculation in the response
        $this->assertEquals(30, $targetResourceData['total_time']); // 30 minutes
        $this->assertEquals(250, $targetResourceData['total_price']); // 500 cents * 0.5 hours = 250 cents
    }

    /**
     * Test of end-to-end multi-day booking.
     * Check that the calculation of the cost and net working minutes for end-to-end slots considers
     * strictly working hours, and not astronomical (taking into account the night).
     */
    public function testOfEndToEndMultiDayBooking(): void
    {
        $this->logInAsClient();

        // Simulating the client's request: startDate - Monday 12:00, endDate - Wednesday 20:00, Duration = 1500 minutes.
        $this->client->request(
            'GET',
            '/api/resources',
            [
                'startDate' => $this->baseTime->format(\DateTimeInterface::ATOM),
                'endDate' => $this->baseTime->modify('+2 day')->setTime(20, 0, 0)->format(\DateTimeInterface::ATOM),
                'duration' => 1500
            ],
            [],
            $this->getAuthHeaders()
        );

        // Validation must be successful (status 200)
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        // Checking that the response contains pagination data and a test resource.
        $this->assertArrayHasKey('data', $responseData);
        $this->assertCount(10, $responseData['data']);

        // Checking the first resource (as an example) in the response
        $checkResourceData = $responseData['data'][0];
        $this->assertEquals('Test Desk № 1', $checkResourceData['title']);

        // A slot must be found from Monday 12:00 to Wednesday 13:00
        $expectedStart = $this->baseTime
            ->setTime(12, 00, 0)
            ->format(\DateTimeInterface::ATOM);
        $expectedEnd = $this->baseTime
            ->modify('+2 day')
            ->setTime(13, 00, 0)
            ->format(\DateTimeInterface::ATOM);

        $this->assertEquals($expectedStart, $checkResourceData['nearest_slot']['start']);
        $this->assertEquals($expectedEnd, $checkResourceData['nearest_slot']['end']);

        // Checking the correctness of the price calculation in the response
        $this->assertEquals(1500, $checkResourceData['total_time']); // 1500 minutes
        $this->assertEquals(12500, $checkResourceData['total_price']); // 500 cents * 25 hours = 12500 cents
    }

    /**
     * Test of occupied resources are excluded from pagination count.
     * Check that the pagination count is recalculated correctly when occupied resources are excluded.
     */
    public function testOccupiedResourcesAreExcludedFromPaginationCount(): void
    {
        $this->logInAsClient();

        $resourceRepository = $this->em->getRepository(Resource::class);
        $allResources = $resourceRepository->findAll();

        $userRepository = $this->em->getRepository(User::class);
        $testUser = $userRepository->findOneBy(['email' => 'client@example.com']);

        // Reserve the first 11 resources out of 12 for a fixed hour (12:00 - 13:00)
        $title = 'Test Desk № 12';
        foreach ($allResources as $resource) {
            if ($resource->getTitle() === $title) {
                continue;
            }

            $startDate = $this->baseTime;
            $startDateDb = $this->getDbDate($startDate, 'start'); // 12:00
            $endDate = $this->baseTime->modify('+1 hour'); // 13:00
            $endDateDb = $this->getDbDate($endDate, 'end');

            $booking = new Booking();
            $booking
                ->setUser($testUser)
                ->setResource($resource)
                ->setStartedAt($startDate) // 12:00
                ->setEndedAt($endDate) // 13:00
                ->setDbBlockedFrom($startDateDb)
                ->setDbBlockedTo($endDateDb)
                ->setTotalPrice(500)
                ->setStatus(BookingStatus::CONFIRMED);
            $this->em->persist($booking);
        }
        $this->em->flush();

        // The client is looking for a strictly fixed interval from 12:00 to 13:00
        $this->client->request(
            'GET',
            '/api/resources',
            [
                'startDate' => $this->baseTime->format(\DateTimeInterface::ATOM),
                'endDate' => $this->baseTime->modify('+1 hour')->format(\DateTimeInterface::ATOM),
            ],
            [],
            $this->getAuthHeaders()
        );

        $this->assertResponseIsSuccessful();
        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        // There should be exactly one free resource left in the pagination (№ 12) - one resource on single page
        $this->assertCount(1, $responseData['data']); // the number of resources on the displayed page - 1
        $this->assertEquals(1, $responseData['meta']['total_pages']); // the total number of pages - 1
    }

    /**
     * Test of bug protection check when bookings locate side by side
     */
    public function testOverlapTechBreak(): void
    {
        $this->logInAsClient();

        // 1. Using a test resource (a place in a coworking space).
        $title = 'Test Desk № 1';
        $resourceRepository = $this->em->getRepository(Resource::class);
        $testResource = $resourceRepository->findOneBy(['title' => $title]);

        // 2. Clearing the database of other resources.
        $resourceRepository = $this->em->getRepository(Resource::class);
        $testResources = $resourceRepository->findAll();
        foreach ($testResources as $resource) {
            if ($resource->getTitle() !== $title)
                $this->em->remove($resource);
        }

        // Using a test user to link an existing booking.
        $testEmail = 'client@example.com';
        $userRepository = $this->em->getRepository(User::class);
        $testUser = $userRepository->findOneBy(['email' => $testEmail]);


        // test booking 09:00 - 10:00 (+ 5 minutes of tech break)
        $startDate = $this->baseTime->setTime(9, 0, 0); // Monday 09:00
        $startDateDb = $this->getDbDate($startDate, 'start');
        $endDate = $this->baseTime->setTime(10, 0, 0); // Monday 10:00
        $endDateDb = $this->getDbDate($endDate, 'end');

        $existingBooking = new Booking();
        $existingBooking
            ->setUser($testUser)
            ->setResource($testResource)
            ->setStartedAt($startDate)
            ->setEndedAt($endDate)
            ->setDbBlockedFrom($startDateDb)
            ->setDbBlockedTo($endDateDb)
            ->setTotalPrice(500)
            ->setStatus(BookingStatus::CONFIRMED);
        $this->em->persist($existingBooking);

        $this->em->flush();

        // 3. Simulating the client's request: Monday 10:01, Duration = 30 minutes.
        // The booking has already ended, but there is still a technical break.
        $mondayEvening = $this->baseTime->setTime(10, 1, 0)->format(\DateTimeInterface::ATOM);

        $this->client->request(
            'GET',
            '/api/resources',
            [
                'startDate' => $mondayEvening,
                //'duration' => 30 // default duration
            ],
            [],
            $this->getAuthHeaders()
        );

        // Validation must be successful (status 200)
        $this->assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        // Checking that the response contains pagination data and a test resource.
        $this->assertArrayHasKey('data', $responseData);
        $this->assertCount(1, $responseData['data']);

        $targetResourceData = $responseData['data'][0];
        $this->assertEquals('Test Desk № 1', $targetResourceData['title']);

        // 4. CHECKING BOOKINGS LOCATED SIDE BY SIDE:
        // The algorithm should detect that although the booking itself has already ended at 10:01 a.m.,
        // there is still a technical break. The nearest slot should start at 10:05, not at 10:01.
        $expectedStart = $this->baseTime->setTime(10, 5, 0)->format(\DateTimeInterface::ATOM);
        $expectedEnd = $this->baseTime->setTime(10, 35, 0)->format(\DateTimeInterface::ATOM);

        $this->assertEquals($expectedStart, $targetResourceData['nearest_slot']['start']);
        $this->assertEquals($expectedEnd, $targetResourceData['nearest_slot']['end']);
    }

    /**
     * Test of the validator's resistance to "garbage" queries with extremely short time frames.
     */
    public function testExtremelyShortWorkingTime(): void
    {
        $this->logInAsClient();

        // Monday 19:50
        $start = $this->baseTime->setTime(19, 50, 0);
        // Monday 19:55
        $end = $this->baseTime->setTime(19, 55, 0);

        $this->client->request(
            'GET',
            '/api/resources',
            [
                'startDate' => $start->format(\DateTimeInterface::ATOM),
                'endDate' => $end->format(\DateTimeInterface::ATOM),
            ],
            [],
            $this->getAuthHeaders()
        );

        // DTO validation error
        $this->assertEquals(422, $this->client->getResponse()->getStatusCode());

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $responseData);
        $errorMessage = 'The interval between start date and end date must be at least 30 minutes.';
        $this->assertStringContainsString($errorMessage, $responseData['errors']['endDate']);
    }
}
