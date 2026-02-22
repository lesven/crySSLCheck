<?php

namespace App\Tests\Integration\Repository;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UserRepository::class)]
class UserRepositoryTest extends IntegrationTestCase
{
    private UserRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(UserRepository::class);
    }

    private function createUser(string $username, string $email, string $role = 'auditor'): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setRole($role);
        $user->setPassword('hashed_password');
        $this->em->persist($user);

        return $user;
    }

    // ── findByEmail ───────────────────────────────────────────────────────────

    public function testFindByEmailReturnsNullWhenNoUserExists(): void
    {
        $result = $this->repository->findByEmail('nobody@example.com');
        $this->assertNull($result);
    }

    public function testFindByEmailReturnsMatchingUser(): void
    {
        $user = $this->createUser('alice', 'alice@example.com');
        $this->em->flush();

        $result = $this->repository->findByEmail('alice@example.com');
        $this->assertNotNull($result);
        $this->assertSame($user->getId(), $result->getId());
    }

    public function testFindByEmailReturnsNullWhenEmailDoesNotMatch(): void
    {
        $this->createUser('alice', 'alice@example.com');
        $this->em->flush();

        $result = $this->repository->findByEmail('other@example.com');
        $this->assertNull($result);
    }

    public function testFindByEmailExcludesSpecifiedId(): void
    {
        $user = $this->createUser('alice', 'alice@example.com');
        $this->em->flush();

        // Editing the same user – should not be detected as a duplicate
        $result = $this->repository->findByEmail('alice@example.com', $user->getId());
        $this->assertNull($result);
    }

    public function testFindByEmailDetectsDuplicateWhenDifferentUserHasSameEmail(): void
    {
        $user1 = $this->createUser('alice', 'shared@example.com');
        $user2 = $this->createUser('bob', 'bob@example.com');
        $this->em->flush();

        // Editing bob and trying to use alice's email
        $result = $this->repository->findByEmail('shared@example.com', $user2->getId());
        $this->assertNotNull($result);
        $this->assertSame($user1->getId(), $result->getId());
    }
}
