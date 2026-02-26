<?php

namespace App\Enum;

/**
 * Monitoring status of a domain.
 */
enum DomainStatus: string
{
    case Active   = 'active';
    case Inactive = 'inactive';
}
