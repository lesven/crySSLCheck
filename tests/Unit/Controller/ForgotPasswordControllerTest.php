<?php

namespace App\Tests\Unit\Controller;

use App\Controller\ForgotPasswordController;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\MailService;
use App\Service\ValidationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(ForgotPasswordController::class)]
class ForgotPasswordControllerTest extends TestCase
{
    private TestableForgotPasswordController $controller;
    private MockObject&UserRepository $userRepository;
    private MockObject&UserPasswordHasherInterface $passwordHasher;
    private MockObject&EntityManagerInterface $entityManager;
    private MockObject&ValidationService $validationService;
    private MockObject&MailService $mailService;

    protected function setUp(): void
    {
        $this->controller = new TestableForgotPasswordController();
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->validationService = $this->createMock(ValidationService::class);
        $this->mailService = $this->createMock(MailService::class);
    }

    public function testRedirectsWhenUserAlreadyLoggedIn(): void
    {
        $this->controller->user = $this->createMock(UserInterface::class);

        $response = $this->controller->forgotPassword(
            Request::create('/forgot-password', 'GET'),
            $this->userRepository,
            $this->passwordHasher,
            $this->entityManager,
            $this->validationService,
            $this->mailService,
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/domain_index', $response->getTargetUrl());
    }

    public function testRendersFormAsNotSubmittedOnGetRequest(): void
    {
        $response = $this->controller->forgotPassword(
            Request::create('/forgot-password', 'GET'),
            $this->userRepository,
            $this->passwordHasher,
            $this->entityManager,
            $this->validationService,
            $this->mailService,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['submitted' => false], $this->controller->lastRenderedParameters);
    }

    public function testResetsPasswordAndFlushesWhenMailWasSent(): void
    {
        $this->controller->csrfValid = true;

        $user = (new User())
            ->setEmail('alice@example.com')
            ->setUsername('alice')
            ->setRole('auditor')
            ->setPassword('old');

        $this->userRepository
            ->expects($this->once())
            ->method('findByEmail')
            ->with('alice@example.com')
            ->willReturn($user);

        $this->validationService
            ->expects($this->once())
            ->method('generatePassword')
            ->willReturn('NewPassword123!');

        $this->passwordHasher
            ->expects($this->once())
            ->method('hashPassword')
            ->with($user, 'NewPassword123!')
            ->willReturn('hashed-new-password');

        $this->mailService
            ->expects($this->once())
            ->method('sendPasswordResetMail')
            ->with('alice@example.com', 'NewPassword123!')
            ->willReturn(true);

        $this->entityManager->expects($this->once())->method('flush');

        $request = Request::create('/forgot-password', 'POST', [
            '_token' => 'valid',
            'email' => 'alice@example.com',
        ]);

        $response = $this->controller->forgotPassword(
            $request,
            $this->userRepository,
            $this->passwordHasher,
            $this->entityManager,
            $this->validationService,
            $this->mailService,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hashed-new-password', $user->getPassword());
        $this->assertSame(['submitted' => true], $this->controller->lastRenderedParameters);
    }

    public function testPostAlwaysReturnsSubmittedEvenWithInvalidCsrfToken(): void
    {
        $this->controller->csrfValid = false;

        $this->userRepository->expects($this->never())->method('findByEmail');
        $this->validationService->expects($this->never())->method('generatePassword');
        $this->mailService->expects($this->never())->method('sendPasswordResetMail');
        $this->entityManager->expects($this->never())->method('flush');

        $request = Request::create('/forgot-password', 'POST', [
            '_token' => 'invalid',
            'email' => 'unknown@example.com',
        ]);

        $response = $this->controller->forgotPassword(
            $request,
            $this->userRepository,
            $this->passwordHasher,
            $this->entityManager,
            $this->validationService,
            $this->mailService,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['submitted' => true], $this->controller->lastRenderedParameters);
    }
}

class TestableForgotPasswordController extends ForgotPasswordController
{
    public ?UserInterface $user = null;
    public bool $csrfValid = true;

    /** @var array<string,mixed> */
    public array $lastRenderedParameters = [];

    public function getUser(): ?UserInterface
    {
        return $this->user;
    }

    protected function isCsrfTokenValid(string $id, ?string $token): bool
    {
        return $this->csrfValid;
    }

    protected function redirectToRoute(string $route, array $parameters = [], int $status = 302): RedirectResponse
    {
        return new RedirectResponse('/' . $route, $status);
    }

    protected function render(string $view, array $parameters = [], ?Response $response = null): Response
    {
        $this->lastRenderedParameters = $parameters;

        return $response ?? new Response('ok', Response::HTTP_OK);
    }
}
