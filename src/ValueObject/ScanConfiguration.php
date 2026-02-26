<?php

namespace App\ValueObject;

/**
 * Immutable value object that bundles all scan-related configuration
 * parameters previously passed as individual constructor arguments.
 */
final readonly class ScanConfiguration
{
    public function __construct(
        public int $scanTimeout = 10,
        public int $retryDelay = 5,
        public int $retryCount = 1,
        public bool $notifyOnUnreachable = false,
        public int $minRsaKeyBits = 2048,
        public int $scanConcurrency = 5,
    ) {
    }
}
