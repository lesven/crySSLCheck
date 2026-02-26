<?php

namespace App\Enum;

/**
 * Lifecycle status of a finding.
 */
enum FindingStatus: string
{
    case New      = 'new';
    case Known    = 'known';
    case Resolved = 'resolved';
}
