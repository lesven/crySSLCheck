<?php

namespace App\Enum;

/**
 * Status of a scan run.
 */
enum ScanRunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';
    case Failed  = 'failed';
}
