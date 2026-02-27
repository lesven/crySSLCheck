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

    private function createUser(string $username, string $email, string $role = 'auditor', bool $notifyAlerts = false): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setRole($role);
        $user->setNotifyAlerts($notifyAlerts);
        $user->setPassword('hashed_password');
        $this->em->persist($user);

        return $user;
    }

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

    public function testFindByEmailExcludesSpecifiedId(): void
    {
        $user = $this->createUser('alice', 'alice@example.com');
        $this->em->flush();

        $result = $this->repository->findByEmail('alice@example.com', $user->getId());
        $this->assertNull($result);
    }

    public function testFindAlertRecipientsReturnsOnlyEnabledUsers(): void
    {
        $enabled = $this->createUser('alice', 'alice@example.com', notifyAlerts: true);
        $this->createUser('bob', 'bob@example.com', notifyAlerts: false);
        $this->createUser('charlie', 'charlie@example.com', notifyAlerts: false);
        $this->em->flush();

        $result = $this->repository->findAlertRecipients();

        $this->assertCount(1, $result);
        $this->assertSame($enabled->getId(), $result[0]->getId());
    }

    public function testFindAlertRecipientsDoesNotReturnDeletedUsers(): void
    {
        $enabled = $this->createUser('alice', 'alice@example.com', notifyAlerts: true);
        $this->em->flush();

        $this->em->remove($enabled);
        $this->em->flush();

        $result = $this->repository->findAlertRecipients();

        $this->assertSame([], $result);
    }
}
