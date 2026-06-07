<?php

namespace App\Services;

use App\Models\ProductFeed;
use App\Models\ProductFeedItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Scrapes products (title, price, image, url) straight from a shop's crawled
 * pages — JSON-LD (Product / ItemList), microdata, and og tags — without
 * relying on platform-specific feed endpoints. Returns clean product rows the
 * product-ad job turns into remarketing creatives; can also persist them into
 * the existing ProductFeedItem store under a synthetic 'crawl' feed.
 */
class ProductScraper
{
    private const LISTING_PATHS = ['', '/products', '/collections/all', '/shop', '/store', '/all', '/catalog'];

    /**
     * @return array<int,array{title:string,price_cents:int,currency:string,image_url:string,product_url:?string,description:?string}>
     */
    public function scrape(string $url, int $max = 24): array
    {
        $origin = $this->origin($url);
        $found  = [];
        $seen   = [];
        $productLinks = [];

        foreach (self::LISTING_PATHS as $path) {
            if (count($found) >= $max) break;
            $page = $path === '' ? $url : $origin . $path;
            $html = $this->fetch($page);
            if ($html === null) {
                continue;
            }
            foreach ($this->fromJsonLd($html, $origin) as $p) {
                $this->add($found, $seen, $p, $max);
            }
            foreach ($this->fromMicrodata($html, $origin) as $p) {
                $this->add($found, $seen, $p, $max);
            }
            // Gather product detail links to deep-scrape if the listing was thin.
            foreach ($this->productLinks($html, $origin) as $link) {
                $productLinks[$link] = true;
            }
        }

        // If listings didn't yield enough, deep-scrape a few product pages.
        if (count($found) < min($max, 8)) {
            foreach (array_slice(array_keys($productLinks), 0, 12) as $link) {
                if (count($found) >= $max) break;
                $html = $this->fetch($link);
                if ($html === null) continue;
                foreach ($this->fromJsonLd($html, $origin) as $p) {
                    if (! $p['product_url']) $p['product_url'] = $link;
                    $this->add($found, $seen, $p, $max);
                }
            }
        }

        return array_values($found);
    }

    /** Persist scraped products under a synthetic 'crawl' feed (requires a workspace). */
    public function persist(int $workspaceId, string $url, array $products): ?ProductFeed
    {
        if (empty($products)) {
            return null;
        }
        $feed = ProductFeed::firstOrCreate(
            ['workspace_id' => $workspaceId, 'source' => 'crawl', 'url' => $url],
            ['status' => 'syncing'],
        );
        foreach ($products as $i => $p) {
            ProductFeedItem::updateOrCreate(
                ['product_feed_id' => $feed->id, 'external_id' => $p['product_url'] ?: ('crawl-' . $i . '-' . md5($p['title']))],
                [
                    'title'        => $p['title'],
                    'description'  => $p['description'] ?? null,
                    'image_url'    => $p['image_url'],
                    'product_url'  => $p['product_url'],
                    'price_cents'  => $p['price_cents'],
                    'currency'     => $p['currency'],
                    'availability' => 'in stock',
                    'raw'          => $p,
                ],
            );
        }
        $feed->update(['status' => 'synced', 'last_synced_at' => now()]);
        return $feed;
    }

    // --- JSON-LD ---

    private function fromJsonLd(string $html, string $origin): array
    {
        $out = [];
        if (! preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
            return $out;
        }
        foreach ($m[1] as $block) {
            $data = json_decode(trim(html_entity_decode($block, ENT_QUOTES | ENT_HTML5)), true);
            if (! is_array($data)) {
                // Some sites concatenate multiple JSON objects — try a lenient split.
                continue;
            }
            $this->walkForProducts($data, $out, $origin);
        }
        return $out;
    }

    private function walkForProducts($node, array &$out, string $origin): void
    {
        if (! is_array($node)) {
            return;
        }
        $type = $node['@type'] ?? null;
        $types = is_array($type) ? array_map('strval', $type) : [(string) $type];
        if (in_array('Product', $types, true)) {
            $p = $this->normalizeProduct($node, $origin);
            if ($p) $out[] = $p;
        }
        // ItemList → itemListElement[].item
        if (in_array('ItemList', $types, true) && ! empty($node['itemListElement'])) {
            foreach ((array) $node['itemListElement'] as $el) {
                $item = $el['item'] ?? $el;
                $this->walkForProducts($item, $out, $origin);
            }
        }
        // @graph and generic nested arrays
        foreach (['@graph', 'mainEntity', 'hasPart'] as $key) {
            if (! empty($node[$key])) {
                foreach ((array) $node[$key] as $child) {
                    $this->walkForProducts($child, $out, $origin);
                }
            }
        }
        // A bare list of products
        if (array_is_list($node)) {
            foreach ($node as $child) {
                $this->walkForProducts($child, $out, $origin);
            }
        }
    }

    private function normalizeProduct(array $node, string $origin): ?array
    {
        $title = trim((string) ($node['name'] ?? ''));
        if ($title === '') {
            return null;
        }
        $image = $node['image'] ?? null;
        if (is_array($image)) {
            $image = $image['url'] ?? ($image[0]['url'] ?? $image[0] ?? null);
        }
        $image = is_string($image) ? $this->abs($image, $origin) : null;
        if (! $image) {
            return null; // a product ad needs a product image
        }

        $offers = $node['offers'] ?? [];
        if (isset($offers[0])) $offers = $offers[0];
        $price    = (string) ($offers['price'] ?? $offers['lowPrice'] ?? $node['price'] ?? '');
        $currency = strtoupper(substr((string) ($offers['priceCurrency'] ?? 'USD'), 0, 3));

        return [
            'title'       => $this->clip($title, 80),
            'price_cents' => $this->priceCents($price),
            'currency'    => $currency ?: 'USD',
            'image_url'   => $image,
            'product_url' => isset($node['url']) ? $this->abs((string) $node['url'], $origin) : null,
            'description' => isset($node['description']) ? $this->clip((string) $node['description'], 140) : null,
        ];
    }

    // --- microdata fallback ---

    private function fromMicrodata(string $html, string $origin): array
    {
        $out = [];
        if (! preg_match_all('#itemtype=["\'][^"\']*schema\.org/Product["\'](.*?)(?=itemtype=|$)#is', $html, $m)) {
            return $out;
        }
        foreach ($m[1] as $chunk) {
            $name  = $this->itemprop($chunk, 'name');
            $img   = $this->itempropImg($chunk);
            $price = $this->itemprop($chunk, 'price');
            if ($name && $img) {
                $out[] = [
                    'title'       => $this->clip($name, 80),
                    'price_cents' => $this->priceCents($price ?? ''),
                    'currency'    => 'USD',
                    'image_url'   => $this->abs($img, $origin),
                    'product_url' => null,
                    'description' => null,
                ];
            }
        }
        return $out;
    }

    private function itemprop(string $chunk, string $prop): ?string
    {
        if (preg_match('#itemprop=["\']' . $prop . '["\'][^>]*content=["\']([^"\']+)#i', $chunk, $m)) {
            return trim($m[1]);
        }
        if (preg_match('#itemprop=["\']' . $prop . '["\'][^>]*>([^<]+)<#i', $chunk, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function itempropImg(string $chunk): ?string
    {
        if (preg_match('#itemprop=["\']image["\'][^>]*(?:src|content)=["\']([^"\']+)#i', $chunk, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function productLinks(string $html, string $origin): array
    {
        $links = [];
        if (preg_match_all('#href=["\']([^"\']*/products?/[^"\']+)["\']#i', $html, $m)) {
            foreach ($m[1] as $href) {
                $u = $this->abs($href, $origin);
                // Drop obvious non-detail links (cart, collections root, query junk).
                if (! preg_match('#/(cart|account|collections)/?$#i', $u)) {
                    $links[$u] = true;
                }
            }
        }
        return array_keys($links);
    }

    // --- helpers ---

    private function add(array &$found, array &$seen, array $p, int $max): void
    {
        if (count($found) >= $max) return;
        $key = md5(($p['product_url'] ?? '') . '|' . $p['title']);
        if (isset($seen[$key])) return;
        $seen[$key] = true;
        $found[] = $p;
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
        } catch (\Throwable) {
            return null;
        }
    }

    private function priceCents(string $value): int
    {
        $value = preg_replace('/[^0-9.,]/', '', $value);
        $value = str_replace(',', '.', (string) $value);
        return (int) round(((float) $value) * 100);
    }

    private function origin(string $url): string
    {
        $p = parse_url($url);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    }

    private function abs(string $u, string $origin): string
    {
        $u = trim(html_entity_decode($u, ENT_QUOTES | ENT_HTML5));
        if (preg_match('#^https?://#i', $u)) return $u;
        if (str_starts_with($u, '//')) return 'https:' . $u;
        if (str_starts_with($u, '/')) return $origin . $u;
        return $origin . '/' . ltrim($u, './');
    }

    private function clip(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }
}
