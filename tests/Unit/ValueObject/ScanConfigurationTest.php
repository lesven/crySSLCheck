<?php

namespace App\Tests\Unit\ValueObject;

use App\ValueObject\ScanConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScanConfiguration::class)]
class ScanConfigurationTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new ScanConfiguration();

        $this->assertSame(10, $config->scanTimeout);
        $this->assertSame(5, $config->retryDelay);
        $this->assertSame(1, $config->retryCount);
        $this->assertFalse($config->notifyOnUnreachable);
        $this->assertSame(2048, $config->minRsaKeyBits);
        $this->assertSame(5, $config->scanConcurrency);
        $this->assertSame(30, $config->connectionRefusedRetryDelay1);
        $this->assertSame(60, $config->connectionRefusedRetryDelay2);
    }

    public function testCustomValues(): void
    {
        $config = new ScanConfiguration(
            scanTimeout: 30,
            retryDelay: 10,
            retryCount: 3,
            notifyOnUnreachable: true,
            minRsaKeyBits: 4096,
            scanConcurrency: 10,
            connectionRefusedRetryDelay1: 15,
            connectionRefusedRetryDelay2: 45,
        );

        $this->assertSame(30, $config->scanTimeout);
        $this->assertSame(10, $config->retryDelay);
        $this->assertSame(3, $config->retryCount);
        $this->assertTrue($config->notifyOnUnreachable);
        $this->assertSame(4096, $config->minRsaKeyBits);
        $this->assertSame(10, $config->scanConcurrency);
        $this->assertSame(15, $config->connectionRefusedRetryDelay1);
        $this->assertSame(45, $config->connectionRefusedRetryDelay2);
    }

    public function testIsReadonly(): void
    {
        $config = new ScanConfiguration(scanTimeout: 15);

        $ref = new \ReflectionClass($config);
        $this->assertTrue($ref->isReadOnly());
    }
}
