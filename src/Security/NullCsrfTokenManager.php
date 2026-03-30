<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * No-op CSRF token manager for the e2e test environment.
 * Always generates a static token and accepts any submitted token as valid.
 */
class NullCsrfTokenManager implements CsrfTokenManagerInterface
{
    public function getToken(string $tokenId): CsrfToken
    {
        return new CsrfToken($tokenId, 'e2e-null-token');
    }

    public function refreshToken(string $tokenId): CsrfToken
    {
        return new CsrfToken($tokenId, 'e2e-null-token');
    }

    public function removeToken(string $tokenId): ?string
    {
        return null;
    }

    public function isTokenValid(CsrfToken $token): bool
    {
        return true;
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        // No-op: CSRF cookies are not set in the e2e environment.
    }
}
