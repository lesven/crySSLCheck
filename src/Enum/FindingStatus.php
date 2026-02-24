<?php

namespace App\Enum;

enum FindingStatus: string
{
    case NEW      = 'new';
    case KNOWN    = 'known';
    case RESOLVED = 'resolved';
}
