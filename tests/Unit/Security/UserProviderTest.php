<?php

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\UserProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

#[CoversClass(UserProvider::class)]
class UserProviderTest extends TestCase
{
    private MockObject&UserRepository $userRepository;
    private UserProvider $provider;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->provider = new UserProvider($this->userRepository);
    }

    public function testLoadUserByIdentifierWithUsername(): void
    {
        $user = $this->createUser('alice', 'alice@example.com');

        $this->userRepository->expects($this->once())->method('findByUsername')->with('alice')->willReturn($user);
        $this->userRepository->expects($this->never())->method('findByEmail');

        $result = $this->provider->loadUserByIdentifier('alice');
        $this->assertSame($user, $result);
    }

    public function testLoadUserByIdentifierWithEmail(): void
    {
        $user = $this->createUser('alice', 'alice@example.com');

        $this->userRepository->expects($this->once())->method('findByEmail')->with('alice@example.com')->willReturn($user);
        $this->userRepository->expects($this->never())->method('findByUsername');

        $result = $this->provider->loadUserByIdentifier('alice@example.com');
        $this->assertSame($user, $result);
    }

    public function testLoadUserByIdentifierFallsBackToEmailWhenUsernameNotFound(): void
    {
        $user = $this->createUser('alice', 'alice@example.com');

        // 'alice' is not a valid email, so username is tried first, then email
        $this->userRepository->expects($this->once())->method('findByUsername')->with('alice')->willReturn(null);
        $this->userRepository->expects($this->once())->method('findByEmail')->with('alice')->willReturn($user);

        $result = $this->provider->loadUserByIdentifier('alice');
        $this->assertSame($user, $result);
    }

    public function testLoadUserByIdentifierFallsBackToUsernameWhenEmailNotFound(): void
    {
        $user = $this->createUser('alice@example.com', 'other@example.com');

        // 'alice@example.com' is a valid email, so email is tried first, then username
        $this->userRepository->expects($this->once())->method('findByEmail')->with('alice@example.com')->willReturn(null);
        $this->userRepository->expects($this->once())->method('findByUsername')->with('alice@example.com')->willReturn($user);

        $result = $this->provider->loadUserByIdentifier('alice@example.com');
        $this->assertSame($user, $result);
    }

    public function testLoadUserByIdentifierThrowsWhenNotFound(): void
    {
        $this->userRepository->expects($this->once())->method('findByUsername')->with('unknown')->willReturn(null);
        $this->userRepository->expects($this->once())->method('findByEmail')->with('unknown')->willReturn(null);

        $this->expectException(UserNotFoundException::class);
        $this->provider->loadUserByIdentifier('unknown');
    }

    public function testRefreshUser(): void
    {
        $user = $this->createUser('alice', 'alice@example.com');

        $this->userRepository->expects($this->once())->method('findByUsername')->with('alice')->willReturn($user);
        $this->userRepository->expects($this->never())->method('findByEmail');

        $result = $this->provider->refreshUser($user);
        $this->assertSame($user, $result);
    }

    public function testSupportsClassForUserEntity(): void
    {
        $this->userRepository->expects($this->atLeastOnce())->method('getClassName')->willReturn(User::class);

        $this->assertTrue($this->provider->supportsClass(User::class));
    }

    public function testDoesNotSupportUnrelatedClass(): void
    {
        $this->userRepository->expects($this->atLeastOnce())->method('getClassName')->willReturn(User::class);

        $this->assertFalse($this->provider->supportsClass(\stdClass::class));
    }

    private function createUser(string $username, string $email): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setPassword('hashed');

        return $user;
    }
}
