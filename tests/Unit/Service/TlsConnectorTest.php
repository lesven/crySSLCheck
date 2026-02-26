<?php

namespace App\Tests\Unit\Service;

use App\Service\TlsConnector;
use App\Service\TlsConnectorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for TlsConnector.
 *
 * Note: actual TLS connections cannot be tested in pure unit tests.
 * These tests verify the class contract, constructor, and interface implementation.
 * Real connectivity is covered by integration tests (ScanCommand tests).
 */
#[CoversClass(TlsConnector::class)]
class TlsConnectorTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $connector = new TlsConnector(new NullLogger());

        $this->assertInstanceOf(TlsConnectorInterface::class, $connector);
    }

    public function testConnectReturnsNullForUnreachableHost(): void
    {
        $connector = new TlsConnector(new NullLogger());

        // RFC 5737: 192.0.2.0/24 is reserved for documentation/testing – guaranteed unreachable
        $result = $connector->connect('192.0.2.1', 443, 1);

        $this->assertNull($result);
    }

    public function testConnectReturnsErrorForNonTlsPort(): void
    {
        $connector = new TlsConnector(new NullLogger());

        // Connect to a host that exists but likely has no TLS on a random high port
        $result = $connector->connect('localhost', 1, 1);

        // Should be null (unreachable) or error array
        $this->assertTrue(
            $result === null || isset($result['error']),
            'Expected null or error array for non-TLS port'
        );
    }
}
