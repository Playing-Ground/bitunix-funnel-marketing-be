<?php

namespace App\Services\Bitunix;

use App\Models\BitunixInvitation;
use App\Models\BitunixOverview;
use App\Models\BitunixUserRanking;
use App\Models\IntegrationToken;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BitunixPartnerService — wraps the private partners.bitunix.com REST API.
 *
 * Auth model: stateless JWT in the `Token:` header (capital T, no Bearer).
 * The token is loaded once from the encrypted `integration_tokens.bitunix_partner`
 * row. JWT TTL is 180 days; refresh by re-pasting via `martech:bitunix-set-token`.
 *
 * Response envelope (always): `{"code":"0","msg":"suc","result":...}`
 *   - `code` is a STRING, not int — compare with "0"
 *   - All money values are decimal STRINGS, never floats
 *   - HTTP status is always 200; errors live inside the envelope
 *
 * Per the reverse-engineered docs in `docs/bitunix-partner-api.md`, this
 * service implements the three "self view" pipelines that don't require
 * iterating sub-partner UIDs:
 *   - overview (KPI metrics)
 *   - invitations (per-VIP-code performance)
 *   - user rankings (top-N users in downline)
 *
 * Team-overview drill-downs (which need a sub-partner uid) are out of scope
 * for V1 — those endpoints can be added later when we wire the per-team page.
 */
class BitunixPartnerService
{
    public const SERVICE_KEY = 'bitunix_partner';

    private readonly string $baseUrl;
    private readonly int $timeout;
    private ?IntegrationToken $tokenRow = null;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.bitunix.base_url'), '/');
        $this->timeout = (int) config('services.bitunix.timeout', 30);
    }

    /**
     * Loads + caches the integration_tokens row for this service.
     */
    public function token(): IntegrationToken
    {
        if ($this->tokenRow === null) {
            $row = IntegrationToken::where('service', self::SERVICE_KEY)->first();
            if ($row === null) {
                throw new BitunixApiException(
                    'No Bitunix Partner JWT stored yet. Run `php artisan martech:bitunix-set-token` first.',
                );
            }
            $this->tokenRow = $row;
        }

        return $this->tokenRow;
    }

    /**
     * Convenience for tests — bypasses the cached token row.
     */
    public function refreshTokenCache(): void
    {
        $this->tokenRow = null;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->withHeaders([
                'Token' => $this->token()->token,
                'Accept' => 'application/json',
                'Referer' => 'https://partners.bitunix.com/',
                'Origin' => 'https://partners.bitunix.com',
                'User-Agent' => 'Bitunix-MarTech-Dashboard/1.0',
            ])
            ->acceptJson();
    }

    /**
     * GET helper — issues the call, validates the envelope, returns `result`.
     *
     * @param  array<string,scalar>  $query
     */
    private function get(string $path, array $query = []): mixed
    {
        $response = $this->http()->get($path, $query);

        return $this->unwrap($response, $path);
    }

    /**
     * Validates the {code, msg, result} envelope.
     */
    private function unwrap(Response $response, string $path): mixed
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw new BitunixApiException(
                "Bitunix API rejected the JWT (HTTP {$response->status()}). The token may have expired — run `martech:bitunix-set-token`.",
                apiCode: (string) $response->status(),
                endpoint: $path,
            );
        }

        if ($response->failed()) {
            throw new BitunixApiException(
                "Bitunix API HTTP error: {$response->status()} on {$path}",
                apiCode: (string) $response->status(),
                endpoint: $path,
            );
        }

        $body = $response->json();
        if (! is_array($body) || ! array_key_exists('code', $body)) {
            throw new BitunixApiException(
                "Bitunix API returned an unexpected body shape on {$path}",
                endpoint: $path,
                payload: is_array($body) ? $body : null,
            );
        }

        // String comparison — Bitunix returns "0" not 0
        if ((string) $body['code'] !== '0') {
            throw new BitunixApiException(
                "Bitunix API error on {$path}: [{$body['code']}] ".((string) ($body['msg'] ?? '')),
                apiCode: (string) $body['code'],
                endpoint: $path,
                payload: $body,
            );
        }

        return $body['result'] ?? null;
    }

    /**
     * Verifies that the stored JWT still works by calling /partner/getUserInfo.
     * Returns the partner profile (uid, email, nickName, etc.) on success.
     */
    public function ping(): array
    {
        $result = $this->get('/partner/getUserInfo');

        return is_array($result) ? $result : [];
    }

    /**
     * Performs a fresh login against /partner/login, stores the resulting JWT
     * encrypted in `integration_tokens`, and returns the parsed result block.
     *
     * Bitunix's web UI MD5-hashes the password before sending. The server
     * accepts either plaintext (`$preHashed=false`, we MD5 here) or the
     * already-hashed value (`$preHashed=true`).
     *
     * If 2FA is enabled on the account, the response carries a non-zero
     * `emailAuthenticatorStatus` / `mobileAuthenticatorStatus` /
     * `googleAuthenticatorStatus` and we throw with a clear message.
     *
     * @return array{token:string,expires_at:CarbonImmutable,raw:array<string,mixed>}
     */
    public function login(string $email, string $password, bool $preHashed = false): array
    {
        $hashed = $preHashed ? strtolower($password) : md5($password);

        $response = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Referer' => 'https://partners.bitunix.com/',
                'Origin' => 'https://partners.bitunix.com',
                'User-Agent' => 'Bitunix-MarTech-Dashboard/1.0',
            ])
            ->acceptJson()
            ->post('/partner/login', [
                'account' => $email,
                'password' => $hashed,
            ]);

        $result = $this->unwrap($response, '/partner/login');

        if (! is_array($result) || empty($result['token'])) {
            throw new BitunixApiException('Bitunix login response missing token field.');
        }

        // Refuse to silently bypass 2FA — surface it clearly.
        $twoFaTriggered = ($result['emailAuthenticatorStatus'] ?? 0) > 0
            || ($result['mobileAuthenticatorStatus'] ?? 0) > 0
            || ($result['googleAuthenticatorStatus'] ?? 0) > 0;
        if ($twoFaTriggered) {
            throw new BitunixApiException(
                'Bitunix login requires a 2FA code (email/SMS/Google Authenticator). '
                .'This dashboard does not yet implement the 2FA challenge step. '
                .'Capture the JWT manually from the browser instead.',
            );
        }

        $jwt = (string) $result['token'];
        $payload = $this->decodeJwtPayload($jwt);
        $expiresAt = isset($payload['exp'])
            ? CarbonImmutable::createFromTimestamp((int) $payload['exp'])
            : CarbonImmutable::now()->addDays(180);

        $this->tokenRow = IntegrationToken::updateOrCreate(
            ['service' => self::SERVICE_KEY],
            [
                'token' => $jwt,
                'expires_at' => $expiresAt,
                'last_refreshed_at' => now(),
                'metadata' => [
                    'login_user_uuid' => $payload['login_user_uuid'] ?? null,
                    'login_user_key' => $payload['login_user_key'] ?? null,
                    'iat' => isset($payload['iat'])
                        ? CarbonImmutable::createFromTimestamp((int) $payload['iat'])->toIso8601String()
                        : null,
                    'exp' => $expiresAt->toIso8601String(),
                ],
            ],
        );

        return [
            'token' => $jwt,
            'expires_at' => $expiresAt,
            'raw' => $result,
        ];
    }

    /**
     * Decodes the middle JWT segment without verifying the signature.
     * The signature is opaque to us — Bitunix signs with HS256 and a secret
     * we never see, so we can only trust the server-side validation when we
     * actually call an endpoint.
     *
     * @return array<string,mixed>
     */
    private function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return [];
        }

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    // -------------------------------------------------------------------------
    // Pipeline 1 — overview KPIs
    // -------------------------------------------------------------------------

    /**
     * Pulls headline metrics for `[start, end)` and upserts ONE row per day.
     *
     * Note: Bitunix's `/overview/v2/metrics` returns aggregated totals across
     * the whole window, not per-day. We chunk the window into single-day calls
     * so we get a daily series in `bitunix_overview`.
     */
    public function fetchOverview(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $written = 0;

        for ($day = $start; $day->lt($end); $day = $day->addDay()) {
            $next = $day->addDay();
            $payload = $this->get('/partner/overview/v2/metrics', [
                'startTime' => $this->isoZ($day),
                'endTime' => $this->isoZ($next),
            ]);

            if (! is_array($payload)) {
                continue;
            }

            BitunixOverview::updateOrCreate(
                ['date' => $day->toDateString()],
                [
                    'registrations' => (int) ($payload['registrations'] ?? 0),
                    'first_deposit_users' => (int) ($payload['firstDepositUsers'] ?? 0),
                    'first_trade_users' => (int) ($payload['firstTradeUsers'] ?? 0),
                    'commission' => (string) ($payload['commission'] ?? '0'),
                    'fee' => (string) ($payload['fee'] ?? '0'),
                    'raw' => $payload,
                ],
            );
            $written++;
        }

        Log::info('BitunixPartnerService.fetchOverview', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'rows' => $written,
        ]);

        return $written;
    }

    // -------------------------------------------------------------------------
    // Pipeline 2 — invitations (per VIP code)
    // -------------------------------------------------------------------------

    /**
     * Pulls per-VIP-code performance, day by day. Bitunix returns one row per
     * (partner-side `vipCode`) per call, so we get day × vipCode rows.
     */
    public function fetchInvitations(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $written = 0;

        for ($day = $start; $day->lt($end); $day = $day->addDay()) {
            $next = $day->addDay();
            $rows = $this->get('/partner/overview/v2/invitations', [
                'startTime' => $this->isoZ($day),
                'endTime' => $this->isoZ($next),
            ]);

            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (! is_array($row) || empty($row['vipCode'])) {
                    continue;
                }

                BitunixInvitation::updateOrCreate(
                    [
                        'date' => $day->toDateString(),
                        'vip_code' => (string) $row['vipCode'],
                    ],
                    [
                        'customer_vip_code' => $row['customerVipCode'] ?? null,
                        'exchange_self_ratio' => (string) ($row['exchangeSelfRatio'] ?? '0'),
                        'exchange_sub_ratio' => (string) ($row['exchangeSubRatio'] ?? '0'),
                        'future_self_ratio' => (string) ($row['futureSelfRatio'] ?? '0'),
                        'future_sub_ratio' => (string) ($row['futureSubRatio'] ?? '0'),
                        'registered_users' => $row['registeredUsers'] !== null ? (int) $row['registeredUsers'] : null,
                        'first_deposit_users' => (int) ($row['firstDepositUsers'] ?? 0),
                        'first_trade_users' => (int) ($row['firstTradeUsers'] ?? 0),
                        'click_users' => $row['clickUsers'] !== null ? (int) $row['clickUsers'] : null,
                        'trading_volume' => (string) ($row['tradingVolume'] ?? '0'),
                        'my_commission' => (string) ($row['myCommission'] ?? '0'),
                    ],
                );
                $written++;
            }
        }

        Log::info('BitunixPartnerService.fetchInvitations', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'rows' => $written,
        ]);

        return $written;
    }

    // -------------------------------------------------------------------------
    // Pipeline 3 — top-user rankings
    // -------------------------------------------------------------------------

    /**
     * Pulls the top-N user rankings per day. The endpoint returns a snapshot
     * of "top users by trade volume" — we tag each row with the day it was
     * pulled so the dashboard can show how the leaderboard evolves over time.
     */
    public function fetchUserRankings(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $written = 0;

        for ($day = $start; $day->lt($end); $day = $day->addDay()) {
            $next = $day->addDay();
            $rows = $this->get('/partner/overview/v2/user_rankings', [
                'startTime' => $this->isoZ($day),
                'endTime' => $this->isoZ($next),
                'isDirectUser' => 'true',
                'orderType' => 0,
            ]);

            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (! is_array($row) || empty($row['uid'])) {
                    continue;
                }

                BitunixUserRanking::updateOrCreate(
                    [
                        'date' => $day->toDateString(),
                        'uid' => (int) $row['uid'],
                    ],
                    [
                        'trade_amount' => (string) ($row['tradeAmount'] ?? '0'),
                        'fee' => (string) ($row['fee'] ?? '0'),
                        'commission' => (string) ($row['commission'] ?? '0'),
                        'deposit_amount' => (string) ($row['depositAmount'] ?? '0'),
                        'withdraw_amount' => (string) ($row['withdrawAmount'] ?? '0'),
                        'asset_balance' => (string) ($row['assetBalance'] ?? '0'),
                        'net_deposit_amount' => (string) ($row['netDepositAmount'] ?? '0'),
                    ],
                );
                $written++;
            }
        }

        Log::info('BitunixPartnerService.fetchUserRankings', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'rows' => $written,
        ]);

        return $written;
    }

    /**
     * Stable ISO-8601 UTC at midnight as Bitunix expects:
     * `2026-04-08T00:00:00.000Z` (note the `.000` and capital `Z`).
     */
    private function isoZ(CarbonImmutable $day): string
    {
        return $day->utc()->startOfDay()->format('Y-m-d\T00:00:00.000\Z');
    }
}
