<?php

namespace App\Tests\Integration\Controller;

use App\Controller\UserController;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(UserController::class)]
class UserControllerTest extends WebTestCase
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

    private function createTestUser(string $username = 'testuser', string $role = 'admin'): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setUsername($username);
        $user->setEmail($username . '@example.com');
        $user->setRole($role);
        $user->setPassword($passwordHasher->hashPassword($user, 'Test123!@#'));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function testIndexPageRequiresAdminRole(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('auditor', 'auditor');

        $client->loginUser($user);
        $client->request('GET', '/admin/users');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testIndexPageRendersForAdmin(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('admin', 'admin');

        $client->loginUser($user);
        $client->request('GET', '/admin/users');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1, h2', 'Benutzer');
    }

    public function testIndexPageDisplaysUsers(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');
        $this->createTestUser('user1', 'auditor');
        $this->createTestUser('user2', 'auditor');

        $client->loginUser($admin);
        $client->request('GET', '/admin/users');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'admin');
        $this->assertSelectorTextContains('body', 'user1');
        $this->assertSelectorTextContains('body', 'user2');
    }

    public function testNewUserPageRendersForAdmin(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('admin', 'admin');

        $client->loginUser($user);
        $client->request('GET', '/admin/users/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="username"]');
        $this->assertSelectorExists('input[name="password"]');
        $this->assertSelectorExists('input[name="email"]');
        $this->assertSelectorExists('select[name="role"], input[name="role"]');
    }

    public function testCreateUserWithValidData(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users/new');

        $form = $crawler->selectButton('Benutzer anlegen')->form([
            'username' => 'newuser',
            'password' => 'NewPassword123!',
            'email' => 'newuser@example.com',
            'role' => 'auditor',
        ]);

        $client->submit($form);

        $this->assertResponseRedirects('/admin/users');
        
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $userRepo = $em->getRepository(User::class);
        $newUser = $userRepo->findByUsername('newuser');

        $this->assertNotNull($newUser);
        $this->assertSame('newuser', $newUser->getUsername());
        $this->assertSame('newuser@example.com', $newUser->getEmail());
        $this->assertSame('auditor', $newUser->getRole());
    }

    public function testCreateUserWithExistingUsername(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');
        $this->createTestUser('existinguser', 'auditor');

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users/new');

        $form = $crawler->selectButton('Benutzer anlegen')->form([
            'username' => 'existinguser',
            'password' => 'Password123!',
            'email' => 'different@example.com',
            'role' => 'auditor',
        ]);

        $client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'bereits vergeben');
    }

    public function testCreateUserWithExistingEmail(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');
        $this->createTestUser('user1', 'auditor');

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users/new');

        $form = $crawler->selectButton('Benutzer anlegen')->form([
            'username' => 'newuser',
            'password' => 'Password123!',
            'email' => 'user1@example.com', // This email already exists
            'role' => 'auditor',
        ]);

        $client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'bereits vergeben');
    }

    public function testCreateUserWithInvalidEmail(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users/new');

        $form = $crawler->selectButton('Benutzer anlegen')->form([
            'username' => 'newuser',
            'password' => 'Password123!',
            'email' => 'invalid-email',
            'role' => 'auditor',
        ]);

        $client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'gültige E-Mail-Adresse');
    }

    public function testCreateUserWithWeakPassword(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users/new');

        $form = $crawler->selectButton('Benutzer anlegen')->form([
            'username' => 'newuser',
            'password' => 'weak',
            'email' => 'newuser@example.com',
            'role' => 'auditor',
        ]);

        $client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error, .alert-danger, ul li');
    }

    public function testCreateUserWithInvalidCsrfToken(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');

        $client->loginUser($admin);
        
        $client->request('POST', '/admin/users/new', [
            'username' => 'newuser',
            'password' => 'Password123!',
            'email' => 'newuser@example.com',
            'role' => 'auditor',
            '_token' => 'invalid-token',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Ungültiges CSRF-Token');
    }

    public function testEditUserPageRendersForAdmin(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');
        $user = $this->createTestUser('user1', 'auditor');

        $client->loginUser($admin);
        $client->request('GET', '/admin/users/' . $user->getId() . '/edit');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="username"][value="user1"]');
        $this->assertSelectorExists('input[name="email"][value="user1@example.com"]');
    }

    public function testEditUserWithValidData(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');
        $user = $this->createTestUser('user1', 'auditor');

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users/' . $user->getId() . '/edit');

        $form = $crawler->selectButton('Speichern')->form([
            'username' => 'updateduser',
            'email' => 'updated@example.com',
            'role' => 'admin',
            'password' => '', // Don't change password
        ]);

        $client->submit($form);

        $this->assertResponseRedirects('/admin/users');
        
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $userRepo = $em->getRepository(User::class);
        $updatedUser = $userRepo->find($user->getId());

        $this->assertSame('updateduser', $updatedUser->getUsername());
        $this->assertSame('updated@example.com', $updatedUser->getEmail());
        $this->assertSame('admin', $updatedUser->getRole());
    }

    public function testEditUserAndChangePassword(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');
        $user = $this->createTestUser('user1', 'auditor');

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users/' . $user->getId() . '/edit');

        $form = $crawler->selectButton('Speichern')->form([
            'username' => 'user1',
            'email' => 'user1@example.com',
            'role' => 'auditor',
            'password' => 'NewPassword123!',
        ]);

        $client->submit($form);

        $this->assertResponseRedirects('/admin/users');
        
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $userRepo = $em->getRepository(User::class);
        $updatedUser = $userRepo->find($user->getId());

        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($passwordHasher->isPasswordValid($updatedUser, 'NewPassword123!'));
    }

    public function testCannotDemoteLastAdmin(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/users/' . $admin->getId() . '/edit');

        $form = $crawler->selectButton('Speichern')->form([
            'username' => 'admin',
            'email' => 'admin@example.com',
            'role' => 'auditor', // Try to demote
            'password' => '',
        ]);

        $client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'letzte Administrator');
        
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $userRepo = $em->getRepository(User::class);
        $stillAdmin = $userRepo->find($admin->getId());

        // Admin should still be admin
        $this->assertSame('admin', $stillAdmin->getRole());
    }

    public function testEditNonExistentUserThrowsException(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');

        $client->loginUser($admin);
        $client->request('GET', '/admin/users/99999/edit');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteUserWithValidCsrfToken(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');
        $user = $this->createTestUser('user1', 'auditor');
        $userId = $user->getId();

        $client->loginUser($admin);
        
        // Get CSRF token from the specific user delete form
        $crawler = $client->request('GET', '/admin/users');
        $deleteForm = $crawler->filter('form[action*="/admin/users/' . $userId . '/delete"]')->first();
        $token = $deleteForm->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/users/' . $userId . '/delete', [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects('/admin/users');
        
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $userRepo = $em->getRepository(User::class);
        $deletedUser = $userRepo->find($userId);

        $this->assertNull($deletedUser);
    }

    public function testCannotDeleteYourself(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');

        $client->loginUser($admin);
        
        // Get CSRF token from the specific user delete form
        $crawler = $client->request('GET', '/admin/users');
        $token = $crawler->filter('form[action*="/delete"] input[name="_token"]')->first()->attr('value');

        $client->request('POST', '/admin/users/' . $admin->getId() . '/delete', [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects('/admin/users');
        
        // Admin should still exist
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $userRepo = $em->getRepository(User::class);
        $stillExists = $userRepo->find($admin->getId());

        $this->assertNotNull($stillExists);
    }

    public function testCannotDeleteLastAdmin(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');
        $auditor = $this->createTestUser('auditor', 'auditor');

        $client->loginUser($auditor);
        $client->loginUser($admin); // Logged in as admin, trying to delete themselves (the last admin)
        
        // Get CSRF token from the specific user delete form
        $crawler = $client->request('GET', '/admin/users');
        $token = $crawler->filter('form[action*="/delete"] input[name="_token"]')->first()->attr('value');

        $client->request('POST', '/admin/users/' . $admin->getId() . '/delete', [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects('/admin/users');
        
        // Admin should still exist
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $userRepo = $em->getRepository(User::class);
        $stillExists = $userRepo->find($admin->getId());

        $this->assertNotNull($stillExists);
    }

    public function testDeleteUserWithInvalidCsrfToken(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');
        $user = $this->createTestUser('user1', 'auditor');
        $userId = $user->getId();

        $client->loginUser($admin);
        
        $client->request('POST', '/admin/users/' . $userId . '/delete', [
            '_token' => 'invalid-token',
        ]);

        $this->assertResponseRedirects('/admin/users');
        
        // User should still exist
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $userRepo = $em->getRepository(User::class);
        $stillExists = $userRepo->find($userId);

        $this->assertNotNull($stillExists);
    }

    public function testAuditorCannotAccessUserManagement(): void
    {
        $client = $this->buildClient();
        $auditor = $this->createTestUser('auditor', 'auditor');
        $user = $this->createTestUser('user1', 'auditor');

        $client->loginUser($auditor);

        $routes = [
            '/admin/users',
            '/admin/users/new',
            '/admin/users/' . $user->getId() . '/edit',
        ];

        foreach ($routes as $route) {
            $client->request('GET', $route);
            $this->assertResponseStatusCodeSame(403, "Auditor should not access $route");
        }
    }
}
