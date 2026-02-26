<?php

namespace App\Enum;

/**
 * Severity levels for findings, ordered from lowest to highest.
 */
enum Severity: string
{
    case Ok       = 'ok';
    case Low      = 'low';
    case Medium   = 'medium';
    case High     = 'high';
    case Critical = 'critical';
}
