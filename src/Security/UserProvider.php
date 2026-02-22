<?php

namespace App\Security;

use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class UserProvider implements UserProviderInterface
{
    public function __construct(private readonly UserRepository $userRepository)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $looksLikeEmail = (bool) filter_var($identifier, FILTER_VALIDATE_EMAIL);

        $user = $looksLikeEmail
            ? ($this->userRepository->findByEmail($identifier) ?? $this->userRepository->findByUsername($identifier))
            : ($this->userRepository->findByUsername($identifier) ?? $this->userRepository->findByEmail($identifier));

        if ($user === null) {
            throw new UserNotFoundException(sprintf('User "%s" not found.', $identifier));
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $this->userRepository->getClassName() === $class
            || is_subclass_of($class, $this->userRepository->getClassName());
    }
}
