<?php

namespace App\Controller;

use App\Service\ValidationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'security_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('domain_index');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error'         => $error,
        ]);
    }

    #[Route('/logout', name: 'security_logout')]
    public function logout(): void
    {
        // Symfony Security handles this automatically.
        throw new \LogicException('This method can be blank – it will be intercepted by the logout key on the firewall.');
    }

    #[Route('/profile/change-password', name: 'security_change_password', methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function changePassword(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        ValidationService $validationService,
    ): Response {
        $user = $this->getUser();
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('change-password', $request->request->get('_token'))) {
                $errors[] = 'Ungültiges CSRF-Token.';
            } else {
                $currentPassword = $request->request->get('current_password', '');
                $newPassword     = $request->request->get('new_password', '');
                $confirmPassword = $request->request->get('confirm_password', '');

                if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
                    $errors[] = 'Das aktuelle Passwort ist falsch.';
                }

                if ($newPassword === '') {
                    $errors[] = 'Bitte ein neues Passwort eingeben.';
                } else {
                    $passwordErrors = $validationService->validatePasswordStrength($newPassword);
                    $errors = array_merge($errors, $passwordErrors);
                }

                if ($newPassword !== $confirmPassword) {
                    $errors[] = 'Die neuen Passwörter stimmen nicht überein.';
                }

                if (empty($errors)) {
                    $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
                    $entityManager->flush();

                    $this->addFlash('success', 'Passwort erfolgreich geändert.');
                    return $this->redirectToRoute('domain_index');
                }
            }
        }

        return $this->render('security/change_password.html.twig', [
            'errors' => $errors,
        ]);
    }
}
