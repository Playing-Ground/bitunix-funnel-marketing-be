<?php

namespace App\Services\Bitunix;

use RuntimeException;

/**
 * Thrown when the Bitunix Partner API rejects a call. Carries the original
 * envelope `code` so callers can branch on token-expiry vs other failures.
 *
 * NOTE: We can't shadow Exception::$code with a readonly typed property, so
 * the API code lives on a separately named property.
 */
class BitunixApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $apiCode = '',
        public readonly ?string $endpoint = null,
        public readonly ?array $payload = null,
    ) {
        parent::__construct($message);
    }

    public function isAuthFailure(): bool
    {
        // Bitunix uses string codes and the dashboard typically logs out
        // on these, but we don't have a confirmed list. The HTTP layer will
        // surface 401/403 separately so this is a soft signal.
        return in_array($this->apiCode, ['3', '401', '403', '10003', '10004', '10005', '40001'], true)
            || str_contains(strtolower($this->getMessage()), 'not logged')
            || str_contains(strtolower($this->getMessage()), 'token');
    }
}
