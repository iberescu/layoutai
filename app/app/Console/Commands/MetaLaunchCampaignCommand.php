<?php

namespace App\Console\Commands;

use App\Services\MetaAdsService;
use Illuminate\Console\Command;

/**
 * Creates the layout.ai acquisition campaign on Meta — PAUSED — for review:
 * Campaign (Leads/Conversions) → Ad Set (USA + small-business/marketing
 * targeting, daily budget, optimize for the signup pixel event) → one Ad per
 * creative. Each ad's link is UTM-tagged per variant for attribution.
 *
 *   php artisan meta:launch
 *   php artisan meta:launch --budget=3000 --no-pixel
 */
class MetaLaunchCampaignCommand extends Command
{
    protected $signature = 'meta:launch
        {--budget=3000 : Daily budget in account-currency MINOR units (EUR cents; 3000 = €30)}
        {--event=LEAD : Pixel conversion event to optimize for}
        {--no-pixel : Optimize for link clicks instead of conversions (cold-pixel fallback)}
        {--name=Layout.ai – $500 Credit (US) : Campaign name}
        {--country=US : ISO-2 country to target (geo_locations); e.g. US, GB, DE}
        {--adset= : Resume — add the creatives/ads to this existing ad set id (skip campaign+adset creation)}';

    protected $description = 'Create the layout.ai FB/IG acquisition campaign (PAUSED) on Meta';

    // Resolved targeting IDs (see ads_read targeting search).
    private const INTERESTS = [
        '6003526234370', // Online advertising
        '6003127206524', // Digital marketing
        '6017268931255', // Productivity software
        '6003371567474', // Entrepreneurship
        '6853952393067', // Marketing services and organizations (replaces deprecated "Facebook for Business")
        '6003074954515', // Sales
    ];
    private const BEHAVIORS = ['6002714898572']; // Small business owners

    // Creatives to run as separate ads (file under app/, + UTM variant tag + copy).
    private const CREATIVES = [
        [
            'key'         => 'v01-classic',
            'file'        => 'storage/app/ad-variants/v01-classic.png',
            'headline'    => '$500 Free Ads Credit',
            'message'     => 'We generate 1,000 ads for your business, test them all, and you keep the winner. Claim your $500 ad credit — limited time.',
            'description' => '1,000 ads tested. You get the winner.',
        ],
        [
            'key'         => 'v04-woman-owner',
            'file'        => 'storage/app/ad-variants/v04-woman-owner.png',
            'headline'    => 'Find your best ad — free',
            'message'     => 'We generate 1,000 ads for your business and test them all. Keep the winner. $500 ad credit — limited time.',
            'description' => '1,000 generated. Tested. Ranked.',
        ],
        [
            'key'         => 'v05-bold-type',
            'file'        => 'storage/app/ad-variants/v05-bold-type.png',
            'headline'    => '$500 Free Ads Credit',
            'message'     => '1,000 ads generated and tested for your business — you keep the winner. Claim your $500 credit today.',
            'description' => 'Limited-time $500 ad credit.',
        ],
        [
            'key'         => 'v06-dashboard',
            'file'        => 'storage/app/ad-variants/v06-dashboard.png',
            'headline'    => 'Ads that actually sell',
            'message'     => 'We generate and test 1,000 ads for your business, then you keep the top performer. $500 credit, on us.',
            'description' => 'Test 1,000. Keep the winner.',
        ],
        [
            'key'         => 'v07-funnel',
            'file'        => 'storage/app/ad-variants/v07-funnel.png',
            'headline'    => 'We test 1,000. You get the winner.',
            'message'     => 'Layout.ai generates 1,000 ads, tests them all, and hands you the winner. Claim your $500 ad credit.',
            'description' => '$500 ad credit, on us.',
        ],
    ];

    public function handle(MetaAdsService $meta): int
    {
        if (! $meta->configured()) {
            $this->error('Meta not configured (META_SYSTEM_USER_TOKEN / META_AD_ACCOUNT_ID).');
            return self::FAILURE;
        }

        $pageId  = (string) config('services.meta.page_id');
        $igId    = config('services.meta.ig_account_id') ?: null;
        $pixelId = $this->option('no-pixel') ? null : (string) config('services.meta.pixel_id');
        $budget  = (int) $this->option('budget');

        if (! $pageId) {
            $this->error('META_PAGE_ID missing.');
            return self::FAILURE;
        }

        $targeting = $meta->targeting([strtoupper((string) $this->option('country'))], self::INTERESTS, self::BEHAVIORS);

        try {
            // Resume mode: reuse an existing ad set (e.g. after flipping the app
            // to Live) and just add the creatives/ads — no orphan campaigns.
            if ($resume = $this->option('adset')) {
                $adSetId = (string) $resume;
                $campaignId = null;
                $this->info("resuming into ad set: {$adSetId}");
                $this->createAds($meta, $adSetId, $pageId, $igId);
                $this->newLine();
                $this->info('Ads created PAUSED.');
                return self::SUCCESS;
            }

            $campaignId = $meta->createCampaign($this->option('name'));
            $this->info("campaign: {$campaignId}");

            // Ad set — try conversions; fall back to link clicks if Meta rejects
            // the cold pixel/event.
            try {
                $adSetId = $meta->createAdSet('US · SMB / marketing / productivity', $campaignId, $budget, $targeting, $pixelId, $this->option('event'));
                $this->info("ad set: {$adSetId} (" . ($pixelId ? "conversions:{$this->option('event')}" : 'link_clicks') . ')');
            } catch (\Throwable $e) {
                if ($pixelId) {
                    $this->warn('conversions optimization rejected (likely cold pixel): ' . $e->getMessage());
                    $this->warn('→ falling back to LINK_CLICKS for now; switch to conversions once the pixel has events.');
                    $adSetId = $meta->createAdSet('US · SMB / marketing / productivity', $campaignId, $budget, $targeting, null);
                    $this->info("ad set: {$adSetId} (link_clicks)");
                } else {
                    throw $e;
                }
            }

            $this->createAds($meta, $adSetId, $pageId, $igId);

            $this->newLine();
            $this->info('All PAUSED. Review in Ads Manager:');
            $acct = ltrim((string) config('services.meta.ad_account_id'), 'act_');
            $this->line("  https://adsmanager.facebook.com/adsmanager/manage/campaigns?act={$acct}&selected_campaign_ids={$campaignId}");
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Upload each creative + create a PAUSED ad for it under the ad set. */
    private function createAds(MetaAdsService $meta, string $adSetId, string $pageId, ?string $igId): void
    {
        foreach (self::CREATIVES as $c) {
            $path = base_path($c['file']);
            if (! is_file($path)) {
                $this->warn("  skip {$c['key']}: missing {$c['file']}");
                continue;
            }
            $link = 'https://layout.ai/create?utm_source=meta&utm_medium=paid_social&utm_campaign=launch_500_credit&utm_content=' . $c['key'];
            $hash = $meta->uploadImage($path);
            $creativeId = $meta->createCreative(
                "creative {$c['key']}", $pageId, $hash, $link,
                $c['message'], $c['headline'], $c['description'], $igId,
            );
            $adId = $meta->createAd("ad {$c['key']}", $adSetId, $creativeId);
            $this->info("  ad {$c['key']}: {$adId} (creative {$creativeId})");
        }
    }
}
