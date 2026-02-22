<?php

namespace App\DataCollector;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

class MailDataCollector extends DataCollector
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function collect(Request $request, Response $response, \Throwable $exception = null): void
    {
        // pull any stored mail attempts out of the session; clear afterwards
        $session = $this->requestStack->getSession();
        $attempts = $session ? $session->get('mailer_debug', []) : [];
        $this->data['attempts'] = $attempts;
        if ($session) {
            $session->remove('mailer_debug');
        }
    }

    public function getName(): string
    {
        return 'app.mailer';
    }

    public function getAttempts(): array
    {
        return $this->data['attempts'] ?? [];
    }

    public function reset(): void
    {
        $this->data = [];
    }
}
