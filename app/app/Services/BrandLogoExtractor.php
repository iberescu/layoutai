<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Extracts a usable brand logo URL from a site when the user didn't upload one,
 * so template + product ads can still carry the brand mark. Priority:
 *   1. apple-touch-icon  (a clean square brand mark on its own background —
 *      renders well in the ad logo chip)
 *   2. og:logo
 *   3. a header <img> whose class/id/alt/src says "logo" (often the wordmark)
 *   4. a sized <link rel="icon">
 * Returns an absolute URL or null. Skips obvious sprites/data-URIs.
 */
class BrandLogoExtractor
{
    public function extract(string $url, ?string $html = null): ?string
    {
        $html ??= $this->fetch($url);
        if ($html === null) {
            return null;
        }
        $origin = $this->origin($url);

        // 1) apple-touch-icon (rel may precede or follow href).
        foreach ([
            '#<link[^>]+rel=["\'][^"\']*apple-touch-icon[^"\']*["\'][^>]+href=["\']([^"\']+)#i',
            '#<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\'][^"\']*apple-touch-icon#i',
        ] as $re) {
            if (preg_match($re, $html, $m)) {
                $u = $this->clean($m[1], $origin);
                if ($u) return $u;
            }
        }

        // 2) og:logo
        if (preg_match('#<meta[^>]+property=["\']og:logo["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
            $u = $this->clean($m[1], $origin);
            if ($u) return $u;
        }

        // 3) header logo <img> — first <img> with "logo" in class/id/alt/src.
        if (preg_match_all('#<img\b[^>]+>#i', substr($html, 0, 40000), $imgs)) {
            foreach ($imgs[0] as $tag) {
                if (! preg_match('#\b(class|id|alt|src|data-src)=["\'][^"\']*logo[^"\']*["\']#i', $tag)) {
                    continue;
                }
                // prefer a real src/data-src over a lazy placeholder
                $src = null;
                if (preg_match('#\bsrc=["\']([^"\']+)["\']#i', $tag, $sm) && ! str_contains($sm[1], 'data:')) {
                    $src = $sm[1];
                } elseif (preg_match('#\bdata-src=["\']([^"\']+)["\']#i', $tag, $dm)) {
                    $src = $dm[1];
                }
                $u = $src ? $this->clean($src, $origin) : null;
                if ($u) return $u;
            }
        }

        // 4) sized icon link (>=32px), else any icon.
        if (preg_match_all('#<link[^>]+rel=["\'][^"\']*icon[^"\']*["\'][^>]*>#i', $html, $links)) {
            $best = null; $bestSize = 0;
            foreach ($links[0] as $tag) {
                if (! preg_match('#href=["\']([^"\']+)["\']#i', $tag, $hm)) continue;
                $u = $this->clean($hm[1], $origin);
                if (! $u) continue;
                $size = preg_match('#sizes=["\'](\d+)#i', $tag, $zm) ? (int) $zm[1] : 16;
                if ($size > $bestSize) { $bestSize = $size; $best = $u; }
            }
            if ($best) return $best;
        }

        // 5) conventional well-known paths (some sites don't link them in
        //    static HTML but still serve them) — probe for a real image.
        foreach (['/apple-touch-icon.png', '/apple-touch-icon-precomposed.png', '/favicon.ico'] as $path) {
            $u = $origin . $path;
            if ($this->isImage($u)) {
                return $u;
            }
        }

        return null;
    }

    private function isImage(string $url): bool
    {
        try {
            $r = Http::timeout(6)->withHeaders(['Range' => 'bytes=0-1024'])->get($url);
            return $r->successful() && str_starts_with(strtolower((string) $r->header('Content-Type')), 'image/');
        } catch (\Throwable) {
            return false;
        }
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
            Log::info('BrandLogoExtractor fetch failed: ' . $e->getMessage());
            return null;
        }
    }

    private function clean(string $u, string $origin): ?string
    {
        $u = trim(html_entity_decode($u, ENT_QUOTES | ENT_HTML5));
        if ($u === '' || str_starts_with($u, 'data:')) return null;
        if (str_contains(strtolower($u), 'sprite')) return null;
        if (preg_match('#^https?://#i', $u)) return $u;
        if (str_starts_with($u, '//')) return 'https:' . $u;
        if (str_starts_with($u, '/')) return $origin . $u;
        return $origin . '/' . ltrim($u, './');
    }

    private function origin(string $url): string
    {
        $p = parse_url($url);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    }
}
