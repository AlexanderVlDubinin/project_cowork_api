<?php

namespace App\Tests\Unit;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    /**
     * Testing the initialization of default values (ID, default role...)
     */
    public function testDefaultValuesOnInitialization(): void
    {
        $user = new User();

        $this->assertNotNull($user->getId());
        $this->assertContains(User::ROLE_USER, $user->getRoles());
        $this->assertInstanceOf(\DateTimeImmutable::class, $user->getCreatedAt());
    }

    /**
     * Testing that the password is hashed during serialization
     */
    public function testSerializationHashesPasswordWithCrc32c(): void
    {
        $user = new User();
        $user->setPassword('my_secret_hash');

        $serialized = serialize($user);

        // The password in the serialized string should not contain a pure hash 'my_secret_hash',
        // as it is run through hash('crc32c', ...)
        $this->assertStringNotContainsString('my_secret_hash', $serialized);
    }
}
