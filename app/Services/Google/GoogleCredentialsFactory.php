<?php

namespace App\Services\Google;

use Google\Auth\Credentials\ServiceAccountCredentials;
use RuntimeException;

/**
 * Builds authenticated Google credentials from the service-account JSON file
 * configured at `services.google.service_account_path`. Centralised so that
 * BigQueryClient + Search Console client share the exact same credentials and
 * we don't accidentally reach for the deprecated `keyFilePath` option.
 */
class GoogleCredentialsFactory
{
    public function __construct(
        private readonly string $serviceAccountPath,
    ) {
        if (! is_file($serviceAccountPath) || ! is_readable($serviceAccountPath)) {
            throw new RuntimeException(
                "Google service-account JSON not found or not readable at: {$serviceAccountPath}",
            );
        }
    }

    /**
     * @param  string|array<int,string>  $scopes
     */
    public function credentials(string|array $scopes): ServiceAccountCredentials
    {
        return new ServiceAccountCredentials($scopes, $this->serviceAccountPath);
    }

    public function path(): string
    {
        return $this->serviceAccountPath;
    }

    /**
     * The GCP project ID embedded in the service-account JSON. Used as a
     * fallback when explicit config isn't supplied.
     */
    public function projectId(): string
    {
        $json = json_decode((string) file_get_contents($this->serviceAccountPath), true);

        if (! is_array($json) || empty($json['project_id'])) {
            throw new RuntimeException('service-account JSON has no project_id field');
        }

        return (string) $json['project_id'];
    }
}
