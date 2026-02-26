<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Default TLS connector that uses PHP stream_socket_client + OpenSSL.
 *
 * Extracted from ScanService to allow mocking in unit tests.
 */
class TlsConnector implements TlsConnectorInterface
{
    private const OPENSSL_KEY_TYPES = [
        OPENSSL_KEYTYPE_RSA => 'RSA',
        OPENSSL_KEYTYPE_DSA => 'DSA',
        OPENSSL_KEYTYPE_DH  => 'DH',
        OPENSSL_KEYTYPE_EC  => 'EC',
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function connect(string $fqdn, int $port, int $timeout): ?array
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert'       => true,
                'capture_peer_cert_chain' => true,
                'verify_peer'             => true,
                'verify_peer_name'        => true,
                'allow_self_signed'       => false,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $result = [];

        try {
            $stream = @stream_socket_client(
                "ssl://{$fqdn}:{$port}",
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if ($stream === false) {
                if ($errno === 0 || stripos($errstr, 'timed out') !== false) {
                    $this->logger->info("UNREACHABLE: {$fqdn}:{$port} - {$errstr}");
                    return null;
                }

                $sslError = openssl_error_string();
                $combinedErrorMsg = $errstr . ($sslError ? ' ' . $sslError : '');
                $isCertError = stripos($combinedErrorMsg, 'certificate') !== false
                    || stripos($combinedErrorMsg, 'ssl') !== false
                    || stripos($combinedErrorMsg, 'self signed') !== false
                    || stripos($combinedErrorMsg, 'unknown ca') !== false;

                if ($isCertError) {
                    $result['chain_error'] = $errstr . ($sslError ? " ({$sslError})" : '');

                    $contextNoVerify = stream_context_create([
                        'ssl' => [
                            'capture_peer_cert' => true,
                            'verify_peer'       => false,
                            'verify_peer_name'  => false,
                            'allow_self_signed' => true,
                        ],
                    ]);

                    $streamRetry = @stream_socket_client(
                        "ssl://{$fqdn}:{$port}",
                        $errno2,
                        $errstr2,
                        $timeout,
                        STREAM_CLIENT_CONNECT,
                        $contextNoVerify
                    );

                    if ($streamRetry !== false) {
                        $params = stream_context_get_params($streamRetry);
                        if (isset($params['options']['ssl']['peer_certificate'])) {
                            $certPem = $params['options']['ssl']['peer_certificate'];
                            $result = array_merge($result, $this->extractCertificateInfo($certPem));
                            $result = array_merge($result, $this->extractPublicKeyInfo($certPem));
                        }

                        $result = array_merge($result, $this->extractStreamMetadata($streamRetry));
                        fclose($streamRetry);
                    }

                    return $result;
                }

                $this->logger->info("ERROR: {$fqdn}:{$port} - {$errstr}");
                return ['error' => $errstr . ($sslError ? " ({$sslError})" : '')];
            }

            $params = stream_context_get_params($stream);
            $result = array_merge($result, $this->extractStreamMetadata($stream));

            if (isset($params['options']['ssl']['peer_certificate'])) {
                $certPem = $params['options']['ssl']['peer_certificate'];
                $result = array_merge($result, $this->extractCertificateInfo($certPem, true));
                $result = array_merge($result, $this->extractPublicKeyInfo($certPem));
            }

            fclose($stream);
            $this->logger->info("OK: {$fqdn}:{$port} - TLS-Check erfolgreich");
            return $result;

        } catch (\Throwable $e) {
            $this->logger->error("EXCEPTION: {$fqdn}:{$port} - " . $e->getMessage());
            if (stripos($e->getMessage(), 'timed out') !== false) {
                return null;
            }
            return ['error' => $e->getMessage()];
        }
    }

    private function extractCertificateInfo(mixed $certPem, bool $withSerial = false): array
    {
        $info = [];
        $certInfo = openssl_x509_parse($certPem);

        if ($certInfo) {
            $info['valid_to']   = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);
            $info['valid_from'] = date('Y-m-d H:i:s', $certInfo['validFrom_time_t']);
            $info['subject']    = $certInfo['subject']['CN'] ?? '';
            $info['issuer']     = $certInfo['issuer']['CN'] ?? '';

            if ($withSerial) {
                $info['serial'] = $certInfo['serialNumberHex'] ?? '';
            }
        }

        return $info;
    }

    private function extractPublicKeyInfo(mixed $certPem): array
    {
        $info = [];
        $pubKey = false;

        if (is_resource($certPem) || is_object($certPem)) {
            $pem = '';
            if (openssl_x509_export($certPem, $pem)) {
                $pubKey = @openssl_pkey_get_public($pem);
            }
        } else {
            $pubKey = @openssl_pkey_get_public($certPem);
        }

        if ($pubKey !== false) {
            $keyDetails = openssl_pkey_get_details($pubKey);
            if ($keyDetails && isset($keyDetails['bits'])) {
                $info['public_key_bits'] = $keyDetails['bits'];
                $info['public_key_type'] = self::OPENSSL_KEY_TYPES[$keyDetails['type']] ?? 'UNKNOWN';
            }
        }

        return $info;
    }

    private function extractStreamMetadata(mixed $stream): array
    {
        $meta = stream_get_meta_data($stream);

        return [
            'protocol'       => $meta['crypto']['protocol'] ?? 'unknown',
            'cipher_name'    => $meta['crypto']['cipher_name'] ?? 'unknown',
            'cipher_bits'    => $meta['crypto']['cipher_bits'] ?? null,
            'cipher_version' => $meta['crypto']['cipher_version'] ?? null,
        ];
    }
}
