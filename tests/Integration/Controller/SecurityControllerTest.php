<?php

namespace App\Tests\Integration\Controller;

use App\Controller\SecurityController;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(SecurityController::class)]
class SecurityControllerTest extends WebTestCase
{
    private function buildClient(): KernelBrowser
    {
        $client = static::createClient();

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($em);
        $metadata   = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        return $client;
    }

    private function createTestUser(string $username = 'testuser', string $password = 'Test123!@#', string $role = 'auditor'): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setUsername($username);
        $user->setEmail($username . '@example.com');
        $user->setRole($role);
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function testLoginPageRendersSuccessfully(): void
    {
        $client = $this->buildClient();
        
        $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="username"]');
        $this->assertSelectorExists('input[name="password"]');
    }

    public function testLoginWithValidCredentials(): void
    {
        $client = $this->buildClient();
        $this->createTestUser('alice', 'Password123!');

        $crawler = $client->request('GET', '/login');
        
        $form = $crawler->selectButton('Anmelden')->form([
            'username' => 'alice',
            'password' => 'Password123!',
        ]);

        $client->submit($form);

        $this->assertResponseRedirects('/domains');
        $client->followRedirect();
        $this->assertResponseIsSuccessful();
    }

    public function testLoginWithInvalidCredentials(): void
    {
        $client = $this->buildClient();
        $this->createTestUser('alice', 'Password123!');

        $crawler = $client->request('GET', '/login');
        
        $form = $crawler->selectButton('Anmelden')->form([
            'username' => 'alice',
            'password' => 'WrongPassword',
        ]);

        $client->submit($form);

        $this->assertResponseRedirects('/login');
        $client->followRedirect();
        $this->assertSelectorExists('.alert-danger, .error');
    }

    public function testLoginRedirectsToDomainsWhenAlreadyAuthenticated(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('alice', 'Password123!');

        $client->loginUser($user);
        $client->request('GET', '/login');

        $this->assertResponseRedirects('/domains');
    }

    public function testChangePasswordPageRequiresAuthentication(): void
    {
        $client = $this->buildClient();
        
        $client->request('GET', '/profile/change-password');

        $this->assertResponseRedirects('/login');
    }

    public function testChangePasswordPageRendersForAuthenticatedUser(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('alice', 'Password123!');

        $client->loginUser($user);
        $client->request('GET', '/profile/change-password');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="current_password"]');
        $this->assertSelectorExists('input[name="new_password"]');
        $this->assertSelectorExists('input[name="confirm_password"]');
        $this->assertSelectorExists('input[name="_token"]');
    }

    public function testChangePasswordWithValidData(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('alice', 'OldPassword123!');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/profile/change-password');

        $form = $crawler->selectButton('Passwort ändern')->form([
            'current_password' => 'OldPassword123!',
            'new_password' => 'NewPassword123!',
            'confirm_password' => 'NewPassword123!',
        ]);

        $client->submit($form);

        $this->assertResponseRedirects('/domains');
        
        // Verify flash message
        $client->followRedirect();
        $this->assertSelectorTextContains('.alert-success, .flash-success', 'Passwort erfolgreich geändert');

        // Verify password was actually changed in database
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $userRepo = $em->getRepository(User::class);
        $updatedUser = $userRepo->find($user->getId());
        
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($passwordHasher->isPasswordValid($updatedUser, 'NewPassword123!'));
        $this->assertFalse($passwordHasher->isPasswordValid($updatedUser, 'OldPassword123!'));
    }

    public function testChangePasswordWithIncorrectCurrentPassword(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('alice', 'OldPassword123!');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/profile/change-password');

        $form = $crawler->selectButton('Passwort ändern')->form([
            'current_password' => 'WrongPassword',
            'new_password' => 'NewPassword123!',
            'confirm_password' => 'NewPassword123!',
        ]);

        $client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Das aktuelle Passwort ist falsch');
    }

    public function testChangePasswordWithMismatchedNewPasswords(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('alice', 'OldPassword123!');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/profile/change-password');

        $form = $crawler->selectButton('Passwort ändern')->form([
            'current_password' => 'OldPassword123!',
            'new_password' => 'NewPassword123!',
            'confirm_password' => 'DifferentPassword123!',
        ]);

        $client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'stimmen nicht überein');
    }

    public function testChangePasswordWithEmptyNewPassword(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('alice', 'OldPassword123!');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/profile/change-password');

        $form = $crawler->selectButton('Passwort ändern')->form([
            'current_password' => 'OldPassword123!',
            'new_password' => '',
            'confirm_password' => '',
        ]);

        $client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Bitte ein neues Passwort eingeben');
    }

    public function testChangePasswordWithWeakPassword(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('alice', 'OldPassword123!');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/profile/change-password');

        $form = $crawler->selectButton('Passwort ändern')->form([
            'current_password' => 'OldPassword123!',
            'new_password' => 'weak',
            'confirm_password' => 'weak',
        ]);

        $client->submit($form);

        $this->assertResponseIsSuccessful();
        // The validation service should report errors about password strength
        $this->assertSelectorExists('.error, .alert-danger, ul li, .form-error');
    }

    public function testChangePasswordWithInvalidCsrfToken(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('alice', 'OldPassword123!');

        $client->loginUser($user);
        
        $client->request('POST', '/profile/change-password', [
            'current_password' => 'OldPassword123!',
            'new_password' => 'NewPassword123!',
            'confirm_password' => 'NewPassword123!',
            '_token' => 'invalid-token',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Ungültiges CSRF-Token');
    }

    public function testLogoutRouteExists(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('alice', 'Password123!');

        $client->loginUser($user);
        
        // Symfony intercepts this route, so we just verify it exists
        // The actual logout functionality is handled by the security component
        $client->request('GET', '/logout');
        
        // Should redirect to login after logout
        $this->assertResponseRedirects();
    }
}
