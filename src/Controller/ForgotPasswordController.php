<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\MailService;
use App\Service\ValidationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class ForgotPasswordController extends AbstractController
{
    #[Route('/forgot-password', name: 'security_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        ValidationService $validationService,
        MailService $mailService,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('domain_index');
        }

        $submitted = false;

        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid('forgot-password', $request->request->get('_token'))) {
                $email = trim((string) $request->request->get('email', ''));
                $user  = $userRepository->findByEmail($email);

                if ($user !== null) {
                    $newPassword = $validationService->generatePassword();
                    $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
                    if ($mailService->sendPasswordResetMail($user->getEmail(), $newPassword)) {
                        $entityManager->flush();
                    }
                }
            }

            // Always show success – never reveal whether the e-mail exists
            $submitted = true;
        }

        return $this->render('security/forgot_password.html.twig', [
            'submitted' => $submitted,
        ]);
    }
}
