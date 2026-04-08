<?php

namespace App\Console\Commands\Pipelines;

use App\Models\IntegrationToken;
use App\Services\Bitunix\BitunixPartnerService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('martech:bitunix-set-token
    {token? : The Bitunix Partner JWT (if omitted, the command will prompt securely)}')]
#[Description('Store / rotate the Bitunix Partner JWT used by the dashboard ETL.')]
class BitunixSetTokenCommand extends Command
{
    public function handle(BitunixPartnerService $bitunix): int
    {
        $token = (string) ($this->argument('token') ?: $this->secret('Paste the Bitunix Partner JWT (input hidden)'));

        if ($token === '') {
            $this->error('No token provided. Aborted.');

            return self::FAILURE;
        }

        // Sanity-check the JWT shape and decode the payload so we can persist
        // the embedded `exp` (180-day TTL) and surface user_uuid for traceability.
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            $this->error('Token does not look like a JWT (expected three dot-separated segments).');

            return self::FAILURE;
        }

        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        if (! is_array($payload) || ! isset($payload['exp'])) {
            $this->error('JWT payload missing `exp` — refusing to store.');

            return self::FAILURE;
        }

        $expiresAt = CarbonImmutable::createFromTimestamp((int) $payload['exp']);
        $issuedAt = isset($payload['iat'])
            ? CarbonImmutable::createFromTimestamp((int) $payload['iat'])
            : null;

        $row = IntegrationToken::updateOrCreate(
            ['service' => BitunixPartnerService::SERVICE_KEY],
            [
                'token' => $token,
                'expires_at' => $expiresAt,
                'last_refreshed_at' => now(),
                'metadata' => [
                    'login_user_uuid' => $payload['login_user_uuid'] ?? null,
                    'login_user_key' => $payload['login_user_key'] ?? null,
                    'iat' => $issuedAt?->toIso8601String(),
                    'exp' => $expiresAt->toIso8601String(),
                ],
            ],
        );

        $this->info('✓ Token stored (encrypted) in integration_tokens.');
        $this->line('  user_uuid : '.($payload['login_user_uuid'] ?? '(missing)'));
        $this->line('  user_key  : '.($payload['login_user_key'] ?? '(missing)'));
        $this->line('  expires   : '.$expiresAt->toIso8601String());
        $this->line('  TTL left  : '.$expiresAt->diffForHumans(now(), true).' days');

        // Round-trip verify by hitting /partner/getUserInfo through the service.
        $bitunix->refreshTokenCache();
        $this->newLine();
        $this->info('Verifying token via /partner/getUserInfo …');

        try {
            $info = $bitunix->ping();
            $this->line('  ✓ uid       : '.($info['uid'] ?? '(missing)'));
            $this->line('  ✓ username  : '.($info['username'] ?? '(missing)'));
            $this->line('  ✓ email     : '.($info['email'] ?? '(missing)'));
        } catch (Throwable $e) {
            $this->error('  ✗ Verification failed: '.$e->getMessage());
            $this->warn('  Token is stored but does not appear to work.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
