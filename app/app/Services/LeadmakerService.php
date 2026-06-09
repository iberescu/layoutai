<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Leadmaker campaigns API (campaigns.leadmaker.ai). Used to
 * auto-create an acquisition campaign per onboarded customer and then poll its
 * status daily. Both calls authenticate with the account API key (Bearer); the
 * status read additionally carries the per-campaign token returned at create.
 */
class LeadmakerService
{
    private string $base;
    private string $key;

    public function __construct()
    {
        $this->base = rtrim((string) config('services.leadmaker.base', 'https://campaigns.leadmaker.ai'), '/');
        $this->key  = (string) config('services.leadmaker.key', '');
    }

    public function configured(): bool
    {
        return $this->key !== '';
    }

    /**
     * POST /api/campaigns. Returns the decoded JSON, which is expected to carry
     * the new campaign's id + status token. Throws on a non-2xx / error body.
     */
    public function createCampaign(array $payload): array
    {
        $res = $this->http()
            ->asJson()
            ->post("{$this->base}/api/campaigns", $payload);

        $json = $res->json() ?? [];
        if (! $res->successful() || isset($json['error'])) {
            throw new \RuntimeException("Leadmaker create campaign failed (HTTP {$res->status()}): " . $this->errorFrom($json, $res->body()));
        }

        return $json;
    }

    /**
     * GET /api/campaigns/{id}/status?token={token}. Returns the decoded JSON
     * status payload. Throws on a non-2xx / error body.
     */
    public function campaignStatus(string $id, string $token): array
    {
        $res = $this->http()
            ->get("{$this->base}/api/campaigns/" . rawurlencode($id) . '/status', ['token' => $token]);

        $json = $res->json() ?? [];
        if (! $res->successful() || isset($json['error'])) {
            throw new \RuntimeException("Leadmaker status failed (HTTP {$res->status()}): " . $this->errorFrom($json, $res->body()));
        }

        return $json;
    }

    /**
     * Authenticated HTTP client. The key is presented BOTH as a Bearer token
     * and as an X-API-Key header, so the request authenticates whichever scheme
     * the Leadmaker API expects.
     */
    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->key)
            ->withHeaders(['X-API-Key' => $this->key])
            ->acceptJson()
            ->timeout(30);
    }

    private function errorFrom(array $json, string $body): string
    {
        $err = $json['error']['message'] ?? $json['error'] ?? $json['message'] ?? $body;

        return is_string($err) ? $err : (string) json_encode($err);
    }

    /** Best-effort pull of the campaign id from a create response. */
    public static function extractId(array $resp): ?string
    {
        foreach ([
            $resp['id'] ?? null,
            $resp['campaign_id'] ?? null,
            $resp['campaign']['id'] ?? null,
            $resp['data']['id'] ?? null,
        ] as $v) {
            if (is_string($v) && $v !== '') {
                return $v;
            }
            if (is_int($v)) {
                return (string) $v;
            }
        }

        return null;
    }

    /** Best-effort pull of the per-campaign status token from a create response. */
    public static function extractToken(array $resp): ?string
    {
        foreach ([
            $resp['token'] ?? null,
            $resp['status_token'] ?? null,
            $resp['campaign']['token'] ?? null,
            $resp['data']['token'] ?? null,
        ] as $v) {
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }

    /**
     * Map an ISO-3166-1 alpha-2 country (the brand's ad-target country) to a
     * representative IANA timezone for the Leadmaker campaign. Falls back to UTC
     * for unknown / unset countries. Covers the markets in config/countries.php.
     */
    public static function timezoneForCountry(?string $cc): string
    {
        $cc = strtoupper(trim((string) $cc));

        return self::COUNTRY_TZ[$cc] ?? 'UTC';
    }

    /** Primary timezone per supported ad-target country. */
    private const COUNTRY_TZ = [
        'US' => 'America/New_York',
        'GB' => 'Europe/London',
        'CA' => 'America/Toronto',
        'AU' => 'Australia/Sydney',
        'IE' => 'Europe/Dublin',
        'NZ' => 'Pacific/Auckland',
        'DE' => 'Europe/Berlin',
        'FR' => 'Europe/Paris',
        'ES' => 'Europe/Madrid',
        'IT' => 'Europe/Rome',
        'NL' => 'Europe/Amsterdam',
        'BE' => 'Europe/Brussels',
        'AT' => 'Europe/Vienna',
        'CH' => 'Europe/Zurich',
        'SE' => 'Europe/Stockholm',
        'NO' => 'Europe/Oslo',
        'DK' => 'Europe/Copenhagen',
        'FI' => 'Europe/Helsinki',
        'PT' => 'Europe/Lisbon',
        'PL' => 'Europe/Warsaw',
        'RO' => 'Europe/Bucharest',
        'SG' => 'Asia/Singapore',
        'AE' => 'Asia/Dubai',
        'IN' => 'Asia/Kolkata',
        'JP' => 'Asia/Tokyo',
        'BR' => 'America/Sao_Paulo',
        'MX' => 'America/Mexico_City',
        'ZA' => 'Africa/Johannesburg',
    ];
}
