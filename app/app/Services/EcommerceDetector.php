<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Detects whether a site is an ecommerce shop and, if so, which platform.
 * Signals: platform fingerprints in the HTML (Shopify / WooCommerce / Magento /
 * BigCommerce / Squarespace Commerce), schema.org Product markup, and
 * og:type=product. Used to decide whether to generate product remarketing ads.
 */
class EcommerceDetector
{
    // STRUCTURAL fingerprints only — asset hosts/paths and framework markup that
    // a site only emits if it's actually BUILT on the platform. Bare brand words
    // ("woocommerce", "shopify") are excluded: payment/SaaS sites mention them as
    // integrations and would false-positive (e.g. stripe.com lists WooCommerce).
    private const PLATFORM_SIGNATURES = [
        'shopify'      => ['cdn.shopify.com', 'myshopify.com', '/cdn/shop/', 'shopify.theme', 'shopify-section', 'data-shopify'],
        'woocommerce'  => ['wp-content/plugins/woocommerce', 'woocommerce-page', 'wc-block-', 'class="woocommerce'],
        'magento'      => ['mage/cookies', 'static/version', 'data-mage-init', 'mage-init', '/pub/static/'],
        'bigcommerce'  => ['cdn11.bigcommerce.com', 'stencil-utils', 'data-stencil'],
        'squarespace'  => ['squarespace-commerce', 'sqs-add-to-cart'],
        'wix'          => ['wixstores', 'data-hook="add-to-cart"'],
        'prestashop'   => ['js-product-miniature', 'prestashop;', 'id="prestashop"'],
    ];

    /**
     * @return array{is_ecommerce:bool, platform:?string, signals:array<int,string>}
     */
    public function detect(string $url, ?string $html = null): array
    {
        $html ??= $this->fetch($url);
        if ($html === null) {
            return ['is_ecommerce' => false, 'platform' => null, 'signals' => []];
        }
        $hay = Str::lower($html);
        $signals  = [];
        $platform = null;

        foreach (self::PLATFORM_SIGNATURES as $name => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($hay, $needle)) {
                    $platform ??= $name;
                    $signals[] = "{$name}:{$needle}";
                    break;
                }
            }
        }

        // Generic ecommerce markers (work even on bespoke storefronts).
        $generic = [
            'schema_product' => (bool) preg_match('#["\']@type["\']\s*:\s*["\']Product["\']#i', $html)
                || str_contains($hay, 'itemtype="http://schema.org/product"')
                || str_contains($hay, 'itemtype="https://schema.org/product"'),
            'og_product'     => (bool) preg_match('#og:type["\'][^>]+content=["\']product#i', $html),
            'add_to_cart'    => str_contains($hay, 'add to cart') || str_contains($hay, 'add to bag') || str_contains($hay, 'add to basket'),
            'price_meta'     => (bool) preg_match('#(itemprop=["\']price["\']|"priceCurrency"|"@type"\s*:\s*"Offer")#i', $html),
        ];
        foreach ($generic as $k => $hit) {
            if ($hit) $signals[] = "generic:{$k}";
        }

        // Decide: a known platform, OR product markup, OR (cart + price) together.
        $isEcommerce = $platform !== null
            || $generic['schema_product']
            || $generic['og_product']
            || ($generic['add_to_cart'] && $generic['price_meta']);

        if ($isEcommerce && $platform === null) {
            $platform = 'generic';
        }

        return ['is_ecommerce' => $isEcommerce, 'platform' => $platform, 'signals' => array_values(array_unique($signals))];
    }

    private function fetch(string $url): ?string
    {
        try {
            $r = Http::withHeaders([
                'User-Agent'      => 'Mozilla/5.0 (compatible; LayoutAIBot/1.0; +https://layout.ai/bot)',
                'Accept'          => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])->timeout(15)->get($url);
            return $r->successful() ? $r->body() : null;
        } catch (\Throwable $e) {
            Log::info('EcommerceDetector fetch failed: ' . $e->getMessage());
            return null;
        }
    }
}
