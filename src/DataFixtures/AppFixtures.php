<?php

namespace App\DataFixtures;

use App\Entity\Booking;
use App\Entity\Resource;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\ResourceType;
use App\Repository\ResourceRepository;
use App\Repository\UserRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    private UserPasswordHasherInterface $passwordHasher;
    private UserRepository $userRepository;
    private ResourceRepository $repository;

    public function __construct(
        UserPasswordHasherInterface $passwordHasher,
        UserRepository $userRepository,
        ResourceRepository $repository,
    )
    {
        $this->passwordHasher = $passwordHasher;
        $this->userRepository = $userRepository;
        $this->repository = $repository;
    }

    public function load(ObjectManager $manager): void
    {
        // $product = new Product();
        // $manager->persist($product);

        // $manager->flush();

        $this->loadUsers($manager);

        $this->loadResources($manager);

        $this->loadBookings($manager);
    }

    public function loadUsers(ObjectManager $manager): void
    {
        /*
        $existingUser = $manager->getRepository(User::class)->findOneBy(['email' => 'admin@admin.com']);

        if ($existingUser) {
            return;
        }
        */

        // Super admin
        $user = new User();
        $user->setFullName('Super User');
        $user->setEmail('superadmin@example.com');
        $user->setPassword($this->passwordHasher->hashPassword($user, 'superpass123!'));
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setCreatedAt(new \DateTimeImmutable());

        $manager->persist($user);

        // admins
        for ($i = 1; $i <= 4; ++$i) {
            $user = new User();
            $user->setFullName('admin'.$i);
            $user->setEmail('admin'.$i.'@example.com');
            $user->setPassword($this->passwordHasher->hashPassword($user, 'admin123456'));
            $user->setRoles(['ROLE_ADMIN']);
            $user->setCreatedAt(new \DateTimeImmutable());

            $manager->persist($user);
        }

        // users
        $faker = Factory::create();
        for ($i = 0; $i < 50; ++$i) {
            $fullName = $faker->firstName().' '.$faker->lastName();
            $email = $faker->email();

            $user = new User();
            $user->setFullName($fullName);
            $user->setEmail($email);
            $user->setPassword($this->passwordHasher->hashPassword($user, 'qwerty123456'));
            $user->setRoles(['ROLE_USER']);
            $user->setCreatedAt(new \DateTimeImmutable());

            $manager->persist($user);
        }
        $manager->flush();
    }

    public function loadResources(ObjectManager $manager): void
    {
        $faker = Factory::create();

        // Meeting rooms
        for ($i = 1; $i <= 4; ++$i) {
            $title = 'Meeting room # '.$i;
            $type = ResourceType::MEETING_ROOM;
            $sentencesNumber = mt_rand(5, 10);
            $description = $faker->sentences($sentencesNumber, true);
            // $isActive = true;
            // $priceRandom = mt_rand(1500, 2800);

            $resource = new Resource();
            $resource->setTitle($title);
            $resource->setType($type);
            $resource->setDescription($description);
            $resource->setIsActive(true);
            $resource->setPricePerHour(2200);

            $manager->persist($resource);
        }

        // Desks
        for ($i = 1; $i <= 36; ++$i) {
            $title = 'Desk # '.$i;
            $type = ResourceType::DESK;
            $sentencesNumber = mt_rand(3, 6);
            $description = $faker->sentences($sentencesNumber, true);
            $randomBoolActive = $faker->boolean(90);
            $isActive = $randomBoolActive;
            // $priceRandom = mt_rand(150, 220);

            $resource = new Resource();
            $resource->setTitle($title);
            $resource->setType($type);
            $resource->setDescription($description);
            $resource->setIsActive($isActive);
            $resource->setPricePerHour(180);

            $manager->persist($resource);
        }
        $manager->flush();
    }

    public function loadBookings(ObjectManager $manager): void {
        /** @var User[] $users */
        $users = $this->userRepository->findOnlyRegularUsers();

        /** @var Resource[] $allActiveResources */
        $allActiveResources = $this->repository->findListForClientByFilters(null);

        if (empty($users) || empty($allActiveResources)) {
            return;
        }

        $currentDate = new \DateTimeImmutable('today');

        // Define intervals: 1 week ago, 1st upcoming week, 2nd upcoming week
        $intervals = [
            ['start' => $currentDate->modify('-7 days'), 'end' => $currentDate, 'target_occupancy' => 0.55],
            ['start' => $currentDate, 'end' => $currentDate->modify('+7 days'), 'target_occupancy' => 0.55],
            ['start' => $currentDate->modify('+7 days'), 'end' => $currentDate->modify('+14 days'), 'target_occupancy' => 0.25],
        ];

        // Array to track user occupation: $userSchedules[userId][dateString][] = [start_timestamp, end_timestamp]
        // Only counts for ACTIVE bookings (cancelled/expired don't block the user's timeline)
        $userSchedules = [];

        foreach ($allActiveResources as $resource) {
            if (!$resource->isActive()) {
                continue;
            }

            foreach ($intervals as $interval) {
                $periodStart = $interval['start'];
                $periodEnd = $interval['end'];
                $targetOccupancy = $interval['target_occupancy'];

                // Total working hours in a day: 12 hours = 720 minutes
                $totalWorkingMinutesPerDay = 720;
                $daysCount = 5;
                $targetMinutesForPeriod = $totalWorkingMinutesPerDay * $daysCount * $targetOccupancy;
                $currentMinutesBooked = 0;

                $dayPointer = $periodStart;

                while ($dayPointer < $periodEnd) {
                    $currentDay = $dayPointer;
                    $dayPointer = $dayPointer->modify('+1 day');

                    // Skip weekends
                    $dayOfWeek = (int)$currentDay->format('N');
                    if ($dayOfWeek > 5) {
                        continue;
                    }

                    if ($currentMinutesBooked >= $targetMinutesForPeriod) {
                        continue;
                    }

                    $timelinePointer = $currentDay->setTime(8, 0, 0);
                    $endOfWorkingDay = $currentDay->setTime(20, 0, 0);

                    while ($timelinePointer < $endOfWorkingDay) {
                        // 1. Generate a random gap between bookings (0 to 180 minutes, multiple of 5)
                        $gapMinutes = rand(0, 36) * 5;
                        $timelinePointer = $timelinePointer->modify("+$gapMinutes minutes");

                        if ($timelinePointer >= $endOfWorkingDay) {
                            break;
                        }

                        // 2. Determine duration based on resource type
                        if ($resource->getType() === 'desk') {
                            $durationMinutes = rand(24, 60) * 5;
                        } else {
                            $durationMinutes = rand(12, 24) * 5;
                        }

                        $bookingStart = $timelinePointer;
                        $bookingEnd = $bookingStart->modify("+$durationMinutes minutes");

                        if ($bookingEnd > $endOfWorkingDay) {
                            $availableMinutes = ($endOfWorkingDay->getTimestamp() - $bookingStart->getTimestamp()) / 60;
                            if ($availableMinutes >= 30) {
                                $durationMinutes = (int) $availableMinutes;
                                $bookingEnd = $endOfWorkingDay;
                            } else {
                                break;
                            }
                        }

                        // 3. Determine booking status based on the timeframe
                        $isPast = $bookingStart < $currentDate;
                        $status = $this->getRandomStatus($isPast);

                        // 4. Find a user who is FREE during this specific time slot
                        $randomUser = null;
                        $shuffledUsers = $users;
                        shuffle($shuffledUsers); // Shuffle to maintain randomness among available users

                        $isCancelledExpiredFailed = ($status === BookingStatus::CANCELLED || $status === BookingStatus::FAILED || $status === BookingStatus::EXPIRED);

                        if ($isCancelledExpiredFailed) {
                            // If the booking is cancelled/expired, the user doesn't actually spend time there.
                            // Anyone can be assigned without overlapping conflicts.
                            $randomUser = $shuffledUsers[0];
                        } else {
                            // For active bookings, find a user whose schedule does not overlap
                            $startTimestamp = $bookingStart->getTimestamp();
                            $endTimestamp = $bookingEnd->getTimestamp();
                            $dateKey = $bookingStart->format('Y-m-d');

                            foreach ($shuffledUsers as $user) {
                                $userId = $user->getId()->toBinary(); // or ->toString() depending on your Uuid type
                                $hasOverlap = false;

                                if (isset($userSchedules[$userId][$dateKey])) {
                                    foreach ($userSchedules[$userId][$dateKey] as $bookedSlot) {
                                        // Check overlap condition: (StartA < EndB) AND (EndA > StartB)
                                        if ($startTimestamp < $bookedSlot['end'] && $endTimestamp > $bookedSlot['start']) {
                                            $hasOverlap = true;
                                            break;
                                        }
                                    }
                                }

                                if (!$hasOverlap) {
                                    $randomUser = $user;
                                    // Log this slot into the user's schedule to block future overlapping attempts
                                    $userSchedules[$userId][$dateKey][] = [
                                        'start' => $startTimestamp,
                                        'end' => $endTimestamp
                                    ];
                                    break;
                                }
                            }
                        }

                        // If all 50 users are busy at this exact time (rare, but possible),
                        // skip this specific slot creation to avoid data corruption and move forward
                        if ($randomUser === null) {
                            $timelinePointer = $bookingEnd->modify('+5 minutes');
                            continue;
                        }

                        // 5. Calculate prices
                        $hours = $durationMinutes / 60;
                        $totalPrice = (int)ceil($resource->getPricePerHour() * $hours);

                        // 6. Create and persist Booking Entity
                        $booking = new Booking();
                        $booking->setStartedAt($bookingStart);
                        $booking->setEndedAt($bookingEnd);
                        $booking->setStatus($status);
                        $booking->setTotalPrice($totalPrice);

                        // Logic for createdAt (in the past)
                        if ($isPast) {
                            $createdAt = $bookingStart->modify('-' . rand(1, 3) . ' days')->setTime(rand(8, 22), rand(0, 59), 0);
                        } else {
                            $createdAt = $currentDate->modify('-' . rand(1, 5) . ' days')->setTime(rand(8, 22), rand(0, 59), 0);
                            if ($createdAt >= $bookingStart) {
                                $createdAt = $bookingStart->modify('-1 days')->setTime(12, 0, 0);
                            }
                        }
                        $booking->setCreatedAt($createdAt);

                        $booking->setUser($randomUser);
                        $booking->setResource($resource);

                        $manager->persist($booking);

                        // Track minutes for target occupancy
                        if (!$isCancelledExpiredFailed) {
                            $currentMinutesBooked += $durationMinutes;
                        }

                        // 7. Move timeline pointer forward + 5 minutes Technical Break
                        $timelinePointer = $bookingEnd->modify('+5 minutes');

                        if ($currentMinutesBooked >= $targetMinutesForPeriod) {
                            break;
                        }
                    }
                }
            }
        }

        $manager->flush();
    }

    /**
     * Helper method to fetch realistic status according to requested constraints.
     */
    private function getRandomStatus(bool $isPast): BookingStatus
    {
        $dice = rand(1, 100);

        if ($isPast) {
            // Past bookings (Last week)
            // ~80% completed, ~5% failed, ~5% expired, ~5% cancelled, ~5% no_show
            if ($dice <= 80) {
                return BookingStatus::COMPLETED;
            }
            if ($dice <= 85) {
                return BookingStatus::FAILED;
            }
            if ($dice <= 90) {
                return BookingStatus::EXPIRED;
            }
            if ($dice <= 95) {
                return BookingStatus::CANCELLED;
            }
            return BookingStatus::NO_SHOW;
        }

        // Future bookings (Next 2 weeks)
        // ~10% pending, ~75% confirmed, ~5% failed,  ~5% expired, ~5% cancelled
        if ($dice <= 10) {
            return BookingStatus::PENDING;
        }
        if ($dice <= 85) {
            return BookingStatus::CONFIRMED;
        }
        if ($dice <= 90) {
            return BookingStatus::FAILED;
        }
        if ($dice <= 95) {
            return BookingStatus::EXPIRED;
        }
        return BookingStatus::CANCELLED;
    }
}
