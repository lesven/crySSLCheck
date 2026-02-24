<?php

namespace App\Enum;

enum Severity: string
{
    case OK       = 'ok';
    case LOW      = 'low';
    case MEDIUM   = 'medium';
    case HIGH     = 'high';
    case CRITICAL = 'critical';
}
