<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Thin Meta Marketing API client for running layout.ai's own FB/IG acquisition
 * campaigns (Campaign → Ad Set → Creative → Ad) + reading per-ad insights.
 * All spend objects are created PAUSED; nothing goes live without an explicit
 * activate call. Conversion optimization points at the layout.ai pixel + the
 * signup ("Lead") event.
 */
class MetaAdsService
{
    private string $ver;
    private string $token;
    private string $act;

    public function __construct()
    {
        $this->ver   = (string) config('services.meta.graph_version', 'v21.0');
        $this->token = (string) config('services.meta.token');
        $this->act   = (string) config('services.meta.ad_account_id'); // act_...
    }

    public function configured(): bool
    {
        return $this->token !== '' && $this->act !== '';
    }

    private function base(): string
    {
        return "https://graph.facebook.com/{$this->ver}";
    }

    /** POST to /{act_id}/{edge}; throws on Meta error with the message. */
    private function post(string $edge, array $params): array
    {
        $params['access_token'] = $this->token;
        $res = Http::asForm()->timeout(60)->post("{$this->base()}/{$this->act}/{$edge}", $params);
        $json = $res->json() ?? [];
        if (! $res->successful() || isset($json['error'])) {
            $err = $json['error']['error_user_msg'] ?? $json['error']['message'] ?? $res->body();
            throw new \RuntimeException("Meta {$edge} failed: {$err}");
        }
        return $json;
    }

    private function get(string $path, array $params = []): array
    {
        $params['access_token'] = $this->token;
        $res = Http::timeout(60)->get("{$this->base()}/{$path}", $params);
        $json = $res->json() ?? [];
        if (! $res->successful() || isset($json['error'])) {
            $err = $json['error']['message'] ?? $res->body();
            throw new \RuntimeException("Meta GET {$path} failed: {$err}");
        }
        return $json;
    }

    /** Upload a creative image; returns its image_hash. */
    public function uploadImage(string $absolutePath): string
    {
        if (! is_file($absolutePath)) {
            throw new \RuntimeException("creative not found: {$absolutePath}");
        }
        $out = $this->post('adimages', ['bytes' => base64_encode((string) file_get_contents($absolutePath))]);
        $img = $out['images'] ?? [];
        $first = is_array($img) ? (array_values($img)[0] ?? []) : [];
        if (empty($first['hash'])) {
            throw new \RuntimeException('image upload returned no hash');
        }
        return $first['hash'];
    }

    public function createCampaign(string $name, string $objective = 'OUTCOME_LEADS'): string
    {
        $out = $this->post('campaigns', [
            'name'                  => $name,
            'objective'             => $objective,
            'status'                => 'PAUSED',
            'special_ad_categories' => '[]',
            // Budgets live on the ad set, not the campaign — Meta requires this
            // flag to be explicit in that case.
            'is_adset_budget_sharing_enabled' => 'false',
        ]);
        return (string) $out['id'];
    }

    /**
     * Create a conversion-optimized ad set: USA + interest/behavior targeting,
     * daily budget (account-currency minor units), optimize for the pixel's
     * signup event. PAUSED.
     */
    public function createAdSet(string $name, string $campaignId, int $dailyBudgetMinor, array $targeting, ?string $pixelId, string $event = 'LEAD'): string
    {
        $params = [
            'name'              => $name,
            'campaign_id'       => $campaignId,
            'daily_budget'      => $dailyBudgetMinor,
            'billing_event'     => 'IMPRESSIONS',
            'bid_strategy'      => 'LOWEST_COST_WITHOUT_CAP',
            'targeting'         => json_encode($targeting),
            'status'            => 'PAUSED',
        ];
        if ($pixelId) {
            $params['optimization_goal'] = 'OFFSITE_CONVERSIONS';
            $params['promoted_object']   = json_encode(['pixel_id' => $pixelId, 'custom_event_type' => $event]);
        } else {
            $params['optimization_goal'] = 'LINK_CLICKS';
        }
        $out = $this->post('adsets', $params);
        return (string) $out['id'];
    }

    /** Single-image link creative. Returns creative id. */
    public function createCreative(string $name, string $pageId, string $imageHash, string $link, string $message, string $headline, string $description, ?string $igId = null, string $cta = 'SIGN_UP'): string
    {
        $linkData = [
            'image_hash'      => $imageHash,
            'link'            => $link,
            'message'         => $message,
            'name'            => $headline,
            'description'     => $description,
            'call_to_action'  => ['type' => $cta, 'value' => ['link' => $link]],
        ];
        $story = ['page_id' => $pageId, 'link_data' => $linkData];
        if ($igId) {
            $story['instagram_actor_id'] = $igId;
        }
        $out = $this->post('adcreatives', [
            'name'              => $name,
            'object_story_spec' => json_encode($story),
        ]);
        return (string) $out['id'];
    }

    public function createAd(string $name, string $adSetId, string $creativeId): string
    {
        $out = $this->post('ads', [
            'name'     => $name,
            'adset_id' => $adSetId,
            'creative' => json_encode(['creative_id' => $creativeId]),
            'status'   => 'PAUSED',
        ]);
        return (string) $out['id'];
    }

    /** USA + interests/behaviors targeting spec for FB+IG. */
    public function usaTargeting(array $interestIds, array $behaviorIds): array
    {
        $group = [];
        if ($interestIds) {
            $group['interests'] = array_map(fn ($id) => ['id' => (string) $id], $interestIds);
        }
        if ($behaviorIds) {
            $group['behaviors'] = array_map(fn ($id) => ['id' => (string) $id], $behaviorIds);
        }
        return [
            'geo_locations'       => ['countries' => ['US']],
            'publisher_platforms' => ['facebook', 'instagram'],
            'flexible_spec'       => [$group],
            'age_min'             => 22,
            // Use our defined interest/behavior targeting, not Advantage+ audience.
            'targeting_automation' => ['advantage_audience' => 0],
        ];
    }

    /**
     * Send a server-side Conversions API event (e.g. signup = "Lead").
     * user_data values like em must be pre-hashed; fbp/fbc/ip/ua are raw.
     * Pass the same $eventId the browser pixel fires for dedup.
     */
    public function sendConversion(string $event, array $userData, array $customData = [], ?string $eventId = null, ?string $eventSourceUrl = null): bool
    {
        $pixel = (string) config('services.meta.pixel_id');
        if ($pixel === '' || $this->token === '') {
            return false;
        }
        $token = (string) (config('services.meta.capi_token') ?: $this->token);
        $event = array_filter([
            'event_name'       => $event,
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_id'         => $eventId,
            'event_source_url' => $eventSourceUrl,
            'user_data'        => $userData ?: null,
            'custom_data'      => $customData ?: null,
        ], fn ($v) => $v !== null && $v !== []);

        try {
            $res = Http::timeout(20)->post(
                "{$this->base()}/{$pixel}/events?access_token=" . urlencode($token),
                ['data' => [$event]],
            );
            return $res->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Per-ad insights for a campaign (spend, clicks, ctr, conversions). */
    public function adInsights(string $campaignId): array
    {
        $out = $this->get("{$campaignId}/insights", [
            'level'  => 'ad',
            'fields' => 'ad_name,impressions,clicks,ctr,spend,actions,cost_per_action_type',
            'date_preset' => 'maximum',
        ]);
        return $out['data'] ?? [];
    }
}
