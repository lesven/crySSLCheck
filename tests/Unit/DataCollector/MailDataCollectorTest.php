<?php

namespace App\Tests\Unit\DataCollector;

use App\DataCollector\MailDataCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[CoversClass(MailDataCollector::class)]
class MailDataCollectorTest extends TestCase
{
    public function testCollectReadsAndClearsSessionAttempts(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $session->set('mailer_debug', [
            ['subject' => 'test', 'success' => true],
        ]);

        $request = Request::create('/_profiler');
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $collector = new MailDataCollector($requestStack);
        $collector->collect($request, new Response());

        $this->assertSame([['subject' => 'test', 'success' => true]], $collector->getAttempts());
        $this->assertFalse($session->has('mailer_debug'));
    }

    public function testCollectWithoutSessionThrowsSessionNotFound(): void
    {
        $requestStack = new RequestStack();
        $collector = new MailDataCollector($requestStack);

        $this->expectException(\Symfony\Component\HttpFoundation\Exception\SessionNotFoundException::class);
        $collector->collect(Request::create('/_profiler'), new Response());
    }

    public function testGetNameAndReset(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $session->set('mailer_debug', [['subject' => 'foo', 'success' => false]]);

        $request = Request::create('/_profiler');
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $collector = new MailDataCollector($requestStack);
        $collector->collect($request, new Response());

        $this->assertSame('app.mailer', $collector->getName());
        $collector->reset();
        $this->assertSame([], $collector->getAttempts());
    }
}
