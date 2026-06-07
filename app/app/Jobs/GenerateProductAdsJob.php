<?php

namespace App\Jobs;

use App\Models\AdVariant;
use App\Models\BrandProfile;
use App\Models\Campaign;
use App\Models\OnboardingSession;
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

    public function handle(EcommerceDetector $detector, ProductScraper $scraper, TemplateAdRenderer $renderer): void
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

        // 2) Scrape products from the crawl.
        $products = $scraper->scrape($brand->website_url, 24);
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
            return $brand->logoAsset?->url();
        } catch (\Throwable) {
            return null;
        }
    }
}
