<?php

namespace App\Enum;

enum FindingType: string
{
    case OK            = 'OK';
    case CERT_EXPIRY   = 'CERT_EXPIRY';
    case TLS_VERSION   = 'TLS_VERSION';
    case CHAIN_ERROR   = 'CHAIN_ERROR';
    case RSA_KEY_LENGTH = 'RSA_KEY_LENGTH';
    case UNREACHABLE   = 'UNREACHABLE';
    case ERROR         = 'ERROR';
}
