<?php

namespace App\Tests\Unit\Command;

use App\Command\SetupCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(SetupCommand::class)]
class SetupCommandTest extends TestCase
{
    private MockObject&EntityManagerInterface $entityManager;
    private MockObject&UserRepository $userRepository;
    private MockObject&UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
    }

    private function buildTester(): CommandTester
    {
        $command = new SetupCommand(
            $this->entityManager,
            $this->userRepository,
            $this->passwordHasher,
        );

        $app = new Application();
        $app->addCommand($command);

        return new CommandTester($app->find('app:setup'));
    }

    public function testCreatesDefaultAdminWhenNoUsersExist(): void
    {
        $this->userRepository
            ->expects($this->once())
            ->method('count')
            ->with([])
            ->willReturn(0);

        $this->passwordHasher
            ->expects($this->once())
            ->method('hashPassword')
            ->with($this->isInstanceOf(User::class), 'admin')
            ->willReturn('hashed-admin-password');

        $this->entityManager
            ->expects($this->once())
            ->method('persist')
            ->with($this->callback(static function (User $user): bool {
                return $user->getUsername() === 'admin'
                    && $user->getRole() === 'admin'
                    && $user->getPassword() === 'hashed-admin-password';
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $tester = $this->buildTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Standard-Admin-Benutzer erstellt', $tester->getDisplay());
    }

    public function testSkipsAdminCreationWhenUsersExist(): void
    {
        $this->userRepository->method('count')->with([])->willReturn(3);

        $this->passwordHasher->expects($this->never())->method('hashPassword');
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $tester = $this->buildTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('überspringe', $tester->getDisplay());
    }
}
