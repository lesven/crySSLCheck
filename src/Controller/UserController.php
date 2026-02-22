<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ValidationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users')]
#[IsGranted('ROLE_ADMIN')]
class UserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidationService $validationService,
    ) {
    }

    #[Route('', name: 'user_index')]
    public function index(): Response
    {
        return $this->render('user/index.html.twig', [
            'users' => $this->userRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $errors = [];
        $username = '';
        $role = 'auditor';
        $email = '';

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('user_new', $request->request->get('_token'))) {
                $errors[] = 'Ungültiges CSRF-Token.';
            } else {
                $username = trim($request->request->get('username', ''));
                $password = $request->request->get('password', '');
                $role     = $request->request->get('role', 'auditor');
                $email    = trim($request->request->get('email', ''));

                if ($username === '') {
                    $errors[] = 'Benutzername darf nicht leer sein.';
                } elseif ($this->userRepository->findByUsername($username) !== null) {
                    $errors[] = 'Dieser Benutzername ist bereits vergeben.';
                }

                if (!in_array($role, ['admin', 'auditor'], true)) {
                    $errors[] = 'Ungültige Rolle.';
                }

                $emailError = $this->validateEmail($email);
                if ($emailError !== null) {
                    $errors[] = $emailError;
                } elseif ($this->userRepository->findByEmail($email) !== null) {
                    $errors[] = 'Diese E-Mail-Adresse ist bereits vergeben.';
                }

                $passwordErrors = $this->validationService->validatePasswordStrength($password);
                $errors = array_merge($errors, $passwordErrors);

                if (empty($errors)) {
                    $user = new User();
                    $user->setUsername($username);
                    $user->setRole($role);
                    $user->setEmail($email);
                    $user->setPassword($this->passwordHasher->hashPassword($user, $password));
                    $this->entityManager->persist($user);
                    $this->entityManager->flush();

                    $this->addFlash('success', sprintf('Benutzer „%s" erfolgreich angelegt.', $username));
                    return $this->redirectToRoute('user_index');
                }
            }
        }

        return $this->render('user/new.html.twig', [
            'errors'   => $errors,
            'username' => $username,
            'role'     => $role,
            'email'    => $email,
        ]);
    }

    #[Route('/{id}/edit', name: 'user_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $user = $this->userRepository->find($id);
        if (!$user) {
            throw $this->createNotFoundException('Benutzer nicht gefunden.');
        }

        $errors = [];
        $username = $user->getUsername();
        $role = $user->getRole();
        $email = $user->getEmail();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('user_edit_' . $id, $request->request->get('_token'))) {
                $errors[] = 'Ungültiges CSRF-Token.';
            } else {
                $username = trim($request->request->get('username', ''));
                $password = $request->request->get('password', '');
                $role     = $request->request->get('role', 'auditor');
                $email    = trim($request->request->get('email', ''));

                if ($username === '') {
                    $errors[] = 'Benutzername darf nicht leer sein.';
                } elseif ($username !== $user->getUsername()) {
                    $existing = $this->userRepository->findByUsername($username);
                    if ($existing !== null) {
                        $errors[] = 'Dieser Benutzername ist bereits vergeben.';
                    }
                }

                if (!in_array($role, ['admin', 'auditor'], true)) {
                    $errors[] = 'Ungültige Rolle.';
                }

                if ($user->isAdmin() && $role !== 'admin' && $this->userRepository->countAdmins() <= 1) {
                    $errors[] = 'Der letzte Administrator kann nicht herabgestuft werden.';
                }

                $emailError = $this->validateEmail($email);
                if ($emailError !== null) {
                    $errors[] = $emailError;
                } elseif ($this->userRepository->findByEmail($email, $user->getId()) !== null) {
                    $errors[] = 'Diese E-Mail-Adresse ist bereits vergeben.';
                }

                if ($password !== '') {
                    $passwordErrors = $this->validationService->validatePasswordStrength($password);
                    $errors = array_merge($errors, $passwordErrors);
                }

                if (empty($errors)) {
                    $user->setUsername($username);
                    $user->setRole($role);
                    $user->setEmail($email);
                    if ($password !== '') {
                        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
                    }
                    $this->entityManager->flush();

                    $this->addFlash('success', sprintf('Benutzer „%s" erfolgreich gespeichert.', $username));
                    return $this->redirectToRoute('user_index');
                }
            }
        }

        return $this->render('user/edit.html.twig', [
            'user'     => $user,
            'errors'   => $errors,
            'username' => $username,
            'role'     => $role,
            'email'    => $email,
        ]);
    }

    #[Route('/{id}/delete', name: 'user_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $user = $this->userRepository->find($id);
        if (!$user) {
            throw $this->createNotFoundException('Benutzer nicht gefunden.');
        }

        if (!$this->isCsrfTokenValid('delete-user-' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('user_index');
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if ($currentUser->getId() === $user->getId()) {
            $this->addFlash('error', 'Sie können sich nicht selbst löschen.');
            return $this->redirectToRoute('user_index');
        }

        if ($user->isAdmin() && $this->userRepository->countAdmins() <= 1) {
            $this->addFlash('error', 'Der letzte Administrator kann nicht gelöscht werden.');
            return $this->redirectToRoute('user_index');
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Benutzer „%s" erfolgreich gelöscht.', $user->getUsername()));
        return $this->redirectToRoute('user_index');
    }

    private function validateEmail(string $email): ?string
    {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Bitte eine gültige E-Mail-Adresse angeben.';
        }

        return null;
    }
}
