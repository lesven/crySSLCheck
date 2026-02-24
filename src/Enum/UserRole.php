<?php

namespace App\Enum;

enum UserRole: string
{
    case ADMIN   = 'admin';
    case AUDITOR = 'auditor';
}
