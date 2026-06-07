<?php

namespace App\Jobs;

use App\Models\AdVariant;
use App\Models\BrandProfile;
use App\Models\Campaign;
use App\Models\OnboardingSession;
use App\Services\BrandImageHarvester;
use App\Services\EcommerceDetector;
use App\Services\ProductScraper;
use App\Services\TemplateAdRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * When the crawled site is an ecommerce shop, builds 20 product remarketing
 * ads (source_type='product') — each featuring one scraped product (image as
 * hero, title, price) on the image-capable pre-built templates. No-ops for
 * non-shops. Detection + scraping read straight from the live site.
 */
class GenerateProductAdsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries   = 1;

    private const PRODUCT_AD_COUNT = 20;

    public function __construct(public int $onboardingSessionId)
    {
        $this->onQueue('ai');
    }

    public function handle(EcommerceDetector $detector, ProductScraper $scraper, TemplateAdRenderer $renderer, BrandImageHarvester $harvester): void
    {
        $session = OnboardingSession::find($this->onboardingSessionId);
        if (! $session || $session->status === 'failed' || ! $session->brand_profile_id) {
            return;
        }
        $brand = BrandProfile::find($session->brand_profile_id);
        $campaign = Campaign::where('brand_profile_id', $brand?->id)->latest('id')->first();
        if (! $brand || ! $campaign) {
            return;
        }

        $session->setStep('product_ads', 'in_progress');

        // Ecommerce verdict was decided + persisted by SummarizeBrandWithGeminiJob
        // (which also skipped the 10 Gemini concept ads for shops). Re-detect
        // only as a fallback if that somehow didn't run.
        $platform = $brand->ecommerce_platform;
        if (! $brand->is_ecommerce && $platform === null) {
            $d = $detector->detect($brand->website_url);
            $brand->update(['is_ecommerce' => $d['is_ecommerce'], 'ecommerce_platform' => $d['platform']]);
            $platform = $d['platform'];
        }
        if (! $brand->is_ecommerce) {
            $session->setStep('product_ads', 'skipped', ['reason' => 'not_ecommerce']);
            return;
        }
        $detection = ['platform' => $platform];

        // 2) Scrape structured products (title/price/image via JSON-LD/microdata),
        //    then prepare a clean, render-safe pool: every product gets a sane
        //    title and a VERIFIED-LOADABLE image (scraped image if it loads,
        //    else a crawl-harvested product photo), backfilled from the crawl
        //    so JS-rendered shops still get product ads.
        $scraped  = $scraper->scrape($brand->website_url, 24);
        $products = $this->prepareProducts($scraped, $harvester, $session, $brand);

        if (empty($products)) {
            $session->setStep('product_ads', 'skipped', ['reason' => 'no_products', 'platform' => $detection['platform']]);
            return;
        }
        // Persist to the product store when we have a workspace (real pipeline).
        if ($session->workspace_id) {
            try {
                $scraper->persist($session->workspace_id, $brand->website_url, $products);
            } catch (\Throwable $e) {
                \Log::info('Product persist skipped: ' . $e->getMessage());
            }
        }

        // 3) Build PRODUCT_AD_COUNT ads across the image-capable templates,
        //    cycling products so each ad features a single product.
        $templates = $renderer->ids(needsImage: true) ?: $renderer->ids();
        $logoUrl   = $this->logoUrl($brand);

        $made = 0;
        for ($i = 0; $i < self::PRODUCT_AD_COUNT; $i++) {
            $product  = $products[$i % count($products)];
            $tplId    = $templates[$i % count($templates)];
            $tpl      = $renderer->template($tplId);
            if (! $tpl) continue;

            $content = [
                'headline'    => $product['title'],
                'subheadline' => '',
                'cta'         => $i % 2 ? 'Shop now' : 'Buy now',
                'logo_url'    => $logoUrl,
                'image_url'   => $product['image_url'],
                'price'       => $this->formatPrice($product['price_cents'], $product['currency']),
            ];

            try {
                $rendered = $renderer->render($tplId, $brand, $content);
            } catch (\Throwable $e) {
                \Log::warning("GenerateProductAdsJob: render {$tplId} failed: " . $e->getMessage());
                continue;
            }

            $platform = in_array([$tpl['width'], $tpl['height']], [[1080, 1080], [1080, 1350], [1080, 1920], [1200, 630]], true)
                ? 'social' : 'display';

            AdVariant::create([
                'campaign_id' => $campaign->id,
                'concept_id'  => null,
                'size_width'  => (int) $tpl['width'],
                'size_height' => (int) $tpl['height'],
                'headline'    => $product['title'],
                'cta'         => $content['cta'],
                'html'        => $rendered['html'],
                'css'         => $rendered['css'] ?: null,
                'layout_type' => $tplId,
                'style'       => 'product',
                'platform'    => $platform,
                'source_type' => 'product',
                'status'      => 'generated',
                'meta'        => [
                    'template_id'   => $tplId,
                    'product_title' => $product['title'],
                    'product_url'   => $product['product_url'],
                    'price'         => $content['price'],
                    'image_url'     => $product['image_url'],
                    'engine'        => 'template',
                ],
            ]);
            $made++;
        }

        $session->setStep('product_ads', 'completed', [
            'count'    => $made,
            'products' => count($products),
            'platform' => $detection['platform'],
        ]);
    }

    /**
     * Build a clean, render-safe product pool (up to 24). Each entry gets a
     * sane title and a VERIFIED-LOADABLE image:
     *   - keep a scraped product as-is when its title is clean AND its image
     *     loads (preserves real name + price + matching photo);
     *   - if the scraped image doesn't load, keep title/price but swap in a
     *     crawl-harvested (already-probed, junk-filtered) product photo;
     *   - reject junk titles (image descriptions, "Spinner", sentences) and
     *     fall back to safe brand copy (no price);
     *   - backfill from harvested photos so JS-rendered shops still get ads.
     */
    private function prepareProducts(array $scraped, BrandImageHarvester $harvester, OnboardingSession $session, BrandProfile $brand): array
    {
        // Loadable harvested product photos (the reliable image pool).
        $imgPool = [];
        foreach ($harvester->harvestFor($session, 24) as $img) {
            if (! empty($img['url'])) $imgPool[] = $img['url'];
        }
        $usedImg = [];
        $nextImg = function () use (&$imgPool, &$usedImg) {
            while ($url = array_shift($imgPool)) {
                if (! isset($usedImg[$url])) { $usedImg[$url] = true; return $url; }
            }
            return null;
        };

        $loadCache = [];
        $loads = function (?string $url) use (&$loadCache) {
            if (! $url) return false;
            return $loadCache[$url] ??= $this->imageLoads($url);
        };

        $out = [];
        $safeIdx = 0;

        foreach ($scraped as $p) {
            if (count($out) >= 24) break;
            $title = $this->cleanTitle($p['title'] ?? '');
            $img   = ($p['image_url'] ?? null);

            if ($img && ! $this->isJunkImage($img) && $loads($img)) {
                $usedImg[$img] = true;            // its own image loads — use it
            } else {
                $img = $nextImg();                // swap to a loadable harvested photo
            }
            if (! $img) continue;                 // no loadable image available at all

            if ($title === null) {
                $title = $this->safeTitle($brand, $safeIdx++);
                $out[] = ['title' => $title, 'price_cents' => 0, 'currency' => 'USD', 'image_url' => $img, 'product_url' => $p['product_url'] ?? null];
            } else {
                $out[] = ['title' => $title, 'price_cents' => (int) ($p['price_cents'] ?? 0), 'currency' => $p['currency'] ?? 'USD', 'image_url' => $img, 'product_url' => $p['product_url'] ?? null];
            }
        }

        // Backfill from remaining harvested photos with safe brand titles.
        while (count($out) < 20) {
            $img = $nextImg();
            if (! $img) break;
            $out[] = ['title' => $this->safeTitle($brand, $safeIdx++), 'price_cents' => 0, 'currency' => 'USD', 'image_url' => $img, 'product_url' => null];
        }

        return $out;
    }

    /** Reject image-descriptions / labels / sentences; return a clean product name or null. */
    private function cleanTitle(string $raw): ?string
    {
        $t = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
        if (mb_strlen($t) < 3 || mb_strlen($t) > 56) {
            return null;
        }
        $low = mb_strtolower($t);
        foreach (['background', 'gradient', 'sketch', 'logo', 'spinner', 'loading', 'icon',
                  'placeholder', 'screenshot', 'lorem', 'undefined', 'null', 'download',
                  'app store', 'google play', 'image of', 'photo of', 'thumbnail'] as $bad) {
            if (str_contains($low, $bad)) return null;
        }
        if (str_word_count($t) >= 8) {            // product names aren't sentences
            return null;
        }
        return $t;
    }

    /** Rotating safe product-ad headline from brand fields (used when no real title). */
    private function safeTitle(BrandProfile $brand, int $i): string
    {
        $company = trim((string) $brand->company_name) ?: 'the collection';
        $pool = [
            $company, "Shop {$company}", "New from {$company}", 'Bestsellers',
            'Shop the collection', 'Customer favorites', 'New arrivals', 'Featured picks',
        ];
        return $pool[$i % count($pool)];
    }

    /** Reject store badges, spinners, sprites, icons scraped as "product" images. */
    private function isJunkImage(string $url): bool
    {
        $low = strtolower($url);
        foreach (['app-store', 'appstore', 'app_store', 'google-play', 'googleplay',
                  'play.google', 'badge', 'spinner', 'loading', 'sprite', 'favicon',
                  '/icon', 'qr-', 'qrcode', '.svg'] as $kw) {
            if (str_contains($low, $kw)) return true;
        }
        return false;
    }

    /** Quick check that an image URL is fetchable + is an image (renderer must load it). */
    private function imageLoads(string $url): bool
    {
        try {
            $r = \Illuminate\Support\Facades\Http::timeout(7)
                ->withHeaders(['Range' => 'bytes=0-2048'])
                ->get($url);
            if (! $r->successful()) return false;
            $ct = strtolower((string) $r->header('Content-Type'));
            return $ct === '' || str_starts_with($ct, 'image/');
        } catch (\Throwable) {
            return false;
        }
    }

    private function formatPrice(int $cents, string $currency): ?string
    {
        if ($cents <= 0) {
            return null;
        }
        $symbol = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CAD' => 'C$', 'AUD' => 'A$'][$currency] ?? '';
        $amount = $cents % 100 === 0 ? number_format($cents / 100, 0) : number_format($cents / 100, 2);
        return $symbol ? "{$symbol}{$amount}" : "{$amount} {$currency}";
    }

    private function logoUrl(BrandProfile $brand): ?string
    {
        try {
            if ($u = $brand->logoAsset?->url()) {
                return $u;
            }
        } catch (\Throwable) {
            // fall through
        }
        return $brand->visual_identity_json['logo_url'] ?? null;
    }
}
