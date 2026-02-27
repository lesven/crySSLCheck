<?php

namespace App\Enum;

/**
 * Types of findings produced by TLS certificate scans.
 */
enum FindingType: string
{
    case Ok           = 'OK';
    case CertExpiry   = 'CERT_EXPIRY';
    case TlsVersion   = 'TLS_VERSION';
    case ChainError   = 'CHAIN_ERROR';
    case RsaKeyLength = 'RSA_KEY_LENGTH';
    case Unreachable  = 'UNREACHABLE';
    case Error        = 'ERROR';
}
