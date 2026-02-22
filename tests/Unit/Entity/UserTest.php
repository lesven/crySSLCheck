<?php

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
class UserTest extends TestCase
{
    private function createUser(string $username = 'testuser', string $role = 'auditor'): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setRole($role);
        $user->setEmail('test@example.com');
        $user->setPassword('hashedpassword');

        return $user;
    }

    public function testDefaultRoleIsAuditor(): void
    {
        $user = new User();
        $this->assertSame('auditor', $user->getRole());
    }

    public function testDefaultEmailIsExampleEmail(): void
    {
        $user = new User();
        $this->assertSame('example@example.com', $user->getEmail());
    }

    public function testIdIsNullBeforePersist(): void
    {
        $user = new User();
        $this->assertNull($user->getId());
    }

    public function testSetAndGetUsername(): void
    {
        $user = $this->createUser('alice');
        $this->assertSame('alice', $user->getUsername());
    }

    public function testGetUserIdentifierReturnsUsername(): void
    {
        $user = $this->createUser('bob');
        $this->assertSame('bob', $user->getUserIdentifier());
    }

    public function testSetAndGetPassword(): void
    {
        $user = $this->createUser();
        $user->setPassword('$2y$13$hashed');
        $this->assertSame('$2y$13$hashed', $user->getPassword());
    }

    public function testSetAndGetEmail(): void
    {
        $user = $this->createUser();
        $user->setEmail('alice@example.com');
        $this->assertSame('alice@example.com', $user->getEmail());
    }

    public function testSetAndGetRole(): void
    {
        $user = $this->createUser('testuser', 'admin');
        $this->assertSame('admin', $user->getRole());

        $user->setRole('auditor');
        $this->assertSame('auditor', $user->getRole());
    }

    public function testGetRolesReturnsArrayWithRolePrefix(): void
    {
        $user = $this->createUser('testuser', 'admin');
        $roles = $user->getRoles();
        
        $this->assertIsArray($roles);
        $this->assertContains('ROLE_ADMIN', $roles);
    }

    public function testGetRolesReturnsAuditorRole(): void
    {
        $user = $this->createUser('testuser', 'auditor');
        $roles = $user->getRoles();
        
        $this->assertIsArray($roles);
        $this->assertContains('ROLE_AUDITOR', $roles);
    }

    public function testIsAdminReturnsTrueForAdmin(): void
    {
        $user = $this->createUser('admin', 'admin');
        $this->assertTrue($user->isAdmin());
    }

    public function testIsAdminReturnsFalseForAuditor(): void
    {
        $user = $this->createUser('auditor', 'auditor');
        $this->assertFalse($user->isAdmin());
    }

    public function testEraseCredentialsDoesNothing(): void
    {
        $user = $this->createUser();
        $user->setPassword('hashedpassword');
        
        $user->eraseCredentials();
        
        // Password should remain unchanged since we don't store plain-text passwords
        $this->assertSame('hashedpassword', $user->getPassword());
    }

    public function testSetUsernameReturnsUserForFluentInterface(): void
    {
        $user = new User();
        $result = $user->setUsername('testuser');
        
        $this->assertInstanceOf(User::class, $result);
        $this->assertSame($user, $result);
    }

    public function testSetPasswordReturnsUserForFluentInterface(): void
    {
        $user = new User();
        $result = $user->setPassword('hashed');
        
        $this->assertInstanceOf(User::class, $result);
        $this->assertSame($user, $result);
    }

    public function testSetRoleReturnsUserForFluentInterface(): void
    {
        $user = new User();
        $result = $user->setRole('admin');
        
        $this->assertInstanceOf(User::class, $result);
        $this->assertSame($user, $result);
    }

    public function testSetEmailReturnsUserForFluentInterface(): void
    {
        $user = new User();
        $result = $user->setEmail('test@example.com');
        
        $this->assertInstanceOf(User::class, $result);
        $this->assertSame($user, $result);
    }

    #[DataProvider('roleTestCaseProvider')]
    public function testRoleConversion(string $role, string $expectedRoleString): void
    {
        $user = $this->createUser('testuser', $role);
        $roles = $user->getRoles();
        
        $this->assertContains($expectedRoleString, $roles);
    }

    public static function roleTestCaseProvider(): array
    {
        return [
            'admin role' => ['admin', 'ROLE_ADMIN'],
            'auditor role' => ['auditor', 'ROLE_AUDITOR'],
        ];
    }
}
