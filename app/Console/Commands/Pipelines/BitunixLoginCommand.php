<?php

namespace App\Console\Commands\Pipelines;

use App\Services\Bitunix\BitunixApiException;
use App\Services\Bitunix\BitunixPartnerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('martech:bitunix-login
    {--email= : The Bitunix Partner account email (omit to be prompted)}
    {--password= : Plaintext password (omit to be prompted, hidden input)}
    {--md5 : Treat --password as an already-MD5-hashed value}')]
#[Description('Log in to Bitunix Partner with email + password and store the resulting JWT.')]
class BitunixLoginCommand extends Command
{
    public function handle(BitunixPartnerService $bitunix): int
    {
        $email = (string) ($this->option('email') ?: $this->ask('Bitunix Partner email'));
        $password = (string) ($this->option('password') ?: $this->secret('Bitunix Partner password (input hidden)'));
        $preHashed = (bool) $this->option('md5');

        if ($email === '' || $password === '') {
            $this->error('Email and password are both required.');

            return self::FAILURE;
        }

        $this->info("Logging in to partners.bitunix.com as {$email} …");

        try {
            $loginResult = $bitunix->login($email, $password, $preHashed);
        } catch (BitunixApiException $e) {
            $this->error('  ✗ Login failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('  ✓ Login successful, JWT stored encrypted in integration_tokens.');
        $this->line('  expires : '.$loginResult['expires_at']->toIso8601String());
        $this->line('  TTL     : '.$loginResult['expires_at']->diffForHumans(now(), true));

        // Round-trip verify by hitting /partner/getUserInfo with the new token.
        $this->newLine();
        $this->info('Verifying token via /partner/getUserInfo …');

        try {
            $info = $bitunix->ping();
            $this->line('  ✓ uid       : '.($info['uid'] ?? '(missing)'));
            $this->line('  ✓ username  : '.($info['username'] ?? '(missing)'));
            $this->line('  ✓ email     : '.($info['email'] ?? '(missing)'));
            $this->line('  ✓ uid       : '.($info['uid'] ?? '(missing)'));
            if (isset($info['allowUserToPartner'])) {
                $this->line('  ✓ partner   : '.($info['allowUserToPartner'] ? 'yes' : 'no'));
            }
        } catch (BitunixApiException $e) {
            $this->error('  ✗ Verification failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
