<?php

namespace App\Enum;

enum DomainStatus: string
{
    case ACTIVE   = 'active';
    case INACTIVE = 'inactive';
}
