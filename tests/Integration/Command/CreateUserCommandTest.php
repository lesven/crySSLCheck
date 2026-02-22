<?php

namespace App\Tests\Integration\Command;

use App\Command\CreateUserCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(CreateUserCommand::class)]
class CreateUserCommandTest extends IntegrationTestCase
{
    private CommandTester $commandTester;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = $this->em->getRepository(User::class);

        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:create-user');
        $this->commandTester = new CommandTester($command);
    }

    public function testCreateUserWithDefaultRole(): void
    {
        $exitCode = $this->commandTester->execute([
            'username' => 'testuser',
            'password' => 'secret123',
            'email' => 'test@example.com',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('erfolgreich erstellt', $this->commandTester->getDisplay());

        $user = $this->userRepository->findByUsername('testuser');
        $this->assertNotNull($user);
        $this->assertSame('testuser', $user->getUsername());
        $this->assertSame('test@example.com', $user->getEmail());
        $this->assertSame('auditor', $user->getRole());
    }

    public function testCreateUserWithAdminRole(): void
    {
        $exitCode = $this->commandTester->execute([
            'username' => 'adminuser',
            'password' => 'adminsecret',
            'email' => 'admin@example.com',
            '--role' => 'admin',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('erfolgreich erstellt', $this->commandTester->getDisplay());

        $user = $this->userRepository->findByUsername('adminuser');
        $this->assertNotNull($user);
        $this->assertSame('admin', $user->getRole());
        $this->assertTrue($user->isAdmin());
    }

    public function testCreateUserWithInvalidRole(): void
    {
        $exitCode = $this->commandTester->execute([
            'username' => 'testuser',
            'password' => 'secret123',
            'email' => 'test@example.com',
            '--role' => 'superuser',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Ungültige Rolle', $this->commandTester->getDisplay());

        $user = $this->userRepository->findByUsername('testuser');
        $this->assertNull($user);
    }

    public function testCreateUserWithInvalidEmail(): void
    {
        $exitCode = $this->commandTester->execute([
            'username' => 'testuser',
            'password' => 'secret123',
            'email' => 'invalid-email',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Ungültige E-Mail-Adresse', $this->commandTester->getDisplay());

        $user = $this->userRepository->findByUsername('testuser');
        $this->assertNull($user);
    }

    public function testCreateUserWithDuplicateUsername(): void
    {
        // Create first user
        $user = new User();
        $user->setUsername('existinguser');
        $user->setEmail('existing@example.com');
        $user->setPassword('hashedpassword');
        $this->em->persist($user);
        $this->em->flush();

        // Try to create another user with the same username
        $exitCode = $this->commandTester->execute([
            'username' => 'existinguser',
            'password' => 'secret123',
            'email' => 'new@example.com',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('existiert bereits', $this->commandTester->getDisplay());
    }

    public function testPasswordIsHashedCorrectly(): void
    {
        $plainPassword = 'MySecretPassword123';

        $exitCode = $this->commandTester->execute([
            'username' => 'testuser',
            'password' => $plainPassword,
            'email' => 'test@example.com',
        ]);

        $this->assertSame(0, $exitCode);

        $user = $this->userRepository->findByUsername('testuser');
        $this->assertNotNull($user);

        // Verify password is hashed (not stored as plain text)
        $this->assertNotSame($plainPassword, $user->getPassword());
        $this->assertStringStartsWith('$', $user->getPassword()); // Bcrypt hash starts with $

        // Verify password can be validated
        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($passwordHasher->isPasswordValid($user, $plainPassword));
        $this->assertFalse($passwordHasher->isPasswordValid($user, 'WrongPassword'));
    }

    public function testCommandOutputIncludesAllUserDetails(): void
    {
        $exitCode = $this->commandTester->execute([
            'username' => 'alice',
            'password' => 'secret',
            'email' => 'alice@example.com',
            '--role' => 'admin',
        ]);

        $this->assertSame(0, $exitCode);
        
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('alice', $output);
        $this->assertStringContainsString('admin', $output);
        $this->assertStringContainsString('alice@example.com', $output);
    }

    public function testShortRoleOption(): void
    {
        $exitCode = $this->commandTester->execute([
            'username' => 'testuser',
            'password' => 'secret123',
            'email' => 'test@example.com',
            '-r' => 'admin',
        ]);

        $this->assertSame(0, $exitCode);

        $user = $this->userRepository->findByUsername('testuser');
        $this->assertNotNull($user);
        $this->assertSame('admin', $user->getRole());
    }
}
