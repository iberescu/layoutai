<?php

namespace App\Services;

use App\Models\CrawlPage;
use App\Models\OnboardingSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ranks the images extracted by CloudflareCrawler / direct HTTP fallback
 * and returns the top N that are usable in ad creatives. Filters out
 * icons, logos, tracking pixels, decorative SVGs, and obvious fallback
 * placeholders. HEAD-probes the survivors to confirm content-type +
 * file size (cheap — no body download).
 */
class BrandImageHarvester
{
    private const TARGET_COUNT = 5;
    // Floor: anything below this is almost certainly a thumbnail, icon, or
    // tracking pixel. Real product / case-study photos on small CMS sites
    // (Webflow, Wix, Squarespace defaults) often max out at ~300x176 so we
    // can't be too strict here — instead the per-slot fit check in
    // GeminiBrandAndAdsService::bindRealImagesByBucket rejects images that
    // are too small for the specific ad they'd land in.
    private const MIN_WIDTH    = 200;
    private const MIN_HEIGHT   = 120;
    private const MIN_BYTES    = 4 * 1024;     //   4 KB — drop tiny icons / spacers
    private const MAX_BYTES    = 10 * 1024 * 1024; // 10 MB — drop hero videos / huge masters

    private const SKIP_HOST_KEYWORDS = ['gravatar', 'doubleclick', 'analytics', 'pixel'];
    private const SKIP_PATH_KEYWORDS = [
        'logo', 'favicon', 'icon', 'sprite', 'avatar', 'thumb-', 'pixel.gif',
        '1x1', 'spacer', 'placeholder', 'loader', 'tracking',
    ];

    /**
     * Pull the top N images across all crawled pages for a session.
     *
     * @return array<int,array{url:string,alt:?string,w:?int,h:?int,bytes:?int,source:string,ratio:?float,bucket:?string}>
     */
    public function harvestFor(OnboardingSession $session, int $count = self::TARGET_COUNT): array
    {
        $pages = CrawlPage::whereHas('crawlJob', fn ($q) => $q->where('onboarding_session_id', $session->id))->get();
        $candidates = $this->collect($pages);

        // Sort hints already on candidates: og: images first, then larger
        // width attributes, then earlier in markup. Probe the top ~20 since
        // some sites have lots of garbage at the bottom of the page.
        usort($candidates, fn ($a, $b) => $this->sortScore($b) <=> $this->sortScore($a));
        $candidates = array_slice($candidates, 0, 20);

        $kept = [];
        foreach ($candidates as $c) {
            if (count($kept) >= $count) break;
            $probed = $this->probe($c);
            if ($probed) {
                $kept[] = $probed;
            }
        }
        return $kept;
    }

    /**
     * Map an image's true aspect ratio to a coarse bucket. Used by the
     * ad-slot matcher: portrait images shouldn't land in landscape slots
     * (and vice versa) — the resulting object-fit:cover crop discards
     * most of the photo and makes the ad look wrong.
     *   landscape : ratio >= 1.30  (typical hero / og:image)
     *   portrait  : ratio <= 0.77  (1 / 1.30 — phone shots, vertical posters)
     *   square    : everything in between (treats as universal match)
     */
    public static function bucketFor(?int $w, ?int $h): ?string
    {
        if (! $w || ! $h) return null;
        $ratio = $w / max(1, $h);
        if ($ratio >= 1.30) return 'landscape';
        if ($ratio <= 0.77) return 'portrait';
        return 'square';
    }

    /**
     * True iff an image is big enough to fill an ad slot cleanly under
     * object-fit: cover. Rule: image must be at least 70% of the slot
     * dimensions on both axes — anything less means the renderer would
     * have to upscale the image significantly and the ad would look soft
     * or blurry. A 300x176 product photo can land in a 300x250 ad slot
     * (passes 70%) but not in a 1080x1080 social card (would need 756+).
     */
    public static function imageFitsSlot(?int $iw, ?int $ih, ?int $sw, ?int $sh): bool
    {
        if (! $iw || ! $ih || ! $sw || ! $sh) return false;
        return $iw >= (int) round($sw * 0.7) && $ih >= (int) round($sh * 0.7);
    }

    /**
     * @param  iterable<\App\Models\CrawlPage>  $pages
     * @return array<int,array{url:string,alt:?string,w:?int,h:?int,source:string}>
     */
    private function collect(iterable $pages): array
    {
        $out = [];
        $seen = [];
        foreach ($pages as $page) {
            foreach (($page->images ?? []) as $img) {
                if (! is_array($img) || empty($img['url'])) continue;
                $u = (string) $img['url'];
                if (isset($seen[$u])) continue;
                if ($this->shouldSkip($u)) continue;
                $seen[$u] = true;
                $out[] = [
                    'url'    => $u,
                    'alt'    => isset($img['alt']) ? (string) $img['alt'] : null,
                    'w'      => isset($img['w'])   ? (int) $img['w']     : null,
                    'h'      => isset($img['h'])   ? (int) $img['h']     : null,
                    'source' => (string) ($img['source'] ?? 'img'),
                ];
            }
        }
        return $out;
    }

    private function shouldSkip(string $url): bool
    {
        $lower = strtolower($url);
        // Format checks — SVG/GIF tend to be icons or decorative; ICO/BMP unusable.
        if (preg_match('~\.(svg|ico|bmp)(\?|$|\#)~i', $url)) return true;

        $host = parse_url($url, PHP_URL_HOST) ?: '';
        foreach (self::SKIP_HOST_KEYWORDS as $kw) {
            if (str_contains($host, $kw)) return true;
        }
        foreach (self::SKIP_PATH_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) return true;
        }
        return false;
    }

    /**
     * Higher = better candidate. og:image gets a strong baseline; explicit
     * width hints add to score; very wide-or-tall ratios subtract (those are
     * usually banners or column dividers, not hero photos).
     */
    private function sortScore(array $c): int
    {
        $score = 0;
        if (($c['source'] ?? '') === 'og') $score += 200;
        $w = (int) ($c['w'] ?? 0);
        $h = (int) ($c['h'] ?? 0);
        if ($w >= self::MIN_WIDTH)  $score += 50;
        if ($h >= self::MIN_HEIGHT) $score += 50;
        if ($w >= 1000) $score += 30;
        if ($w && $h) {
            $ratio = $w / max(1, $h);
            if ($ratio < 0.3 || $ratio > 5.0) $score -= 80;
        }
        $lower = strtolower($c['url']);
        foreach (['hero', 'banner', 'cover', 'feature', 'product'] as $kw) {
            if (str_contains($lower, $kw)) { $score += 25; break; }
        }
        return $score;
    }

    /**
     * Probe the image and return it enriched with content-type, bytes, and
     * TRUE width/height + ratio bucket. Returns null when the server doesn't
     * respond, returns a tiny image, returns a non-image content type, or
     * is suspiciously huge.
     *
     * We do a Range GET of the first 96KB rather than HEAD because:
     *   - markup-supplied width/height attrs are routinely wrong (lazy-load
     *     placeholders, CSS-resized, or absent on og:image entirely)
     *   - JPEG/PNG/WebP encode true dimensions in their first few KB, so a
     *     partial download is enough for getimagesizefromstring()
     *   - Content-Length still tells us if the FULL image is too large
     */
    private function probe(array $c): ?array
    {
        try {
            $r = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; LayoutAIBot/1.0; +https://layout.ai/bot)',
                    'Range'      => 'bytes=0-98303',
                    'Accept'     => 'image/*',
                ])
                ->timeout(8)
                ->withOptions(['allow_redirects' => true])
                ->get($c['url']);
        } catch (\Throwable $e) {
            return null;
        }
        if (! $r->successful()) return null;

        $ctype = strtolower((string) $r->header('Content-Type'));
        if (! str_starts_with($ctype, 'image/')) return null;
        if (str_contains($ctype, 'svg') || str_contains($ctype, 'gif') || str_contains($ctype, 'icon')) return null;

        // Content-Range tells us the full image size (e.g. "bytes 0-98303/512000").
        // Fall back to Content-Length when the server ignored the Range header.
        $fullBytes = $this->fullSizeFromHeaders($r);
        if ($fullBytes !== null && ($fullBytes < self::MIN_BYTES || $fullBytes > self::MAX_BYTES)) {
            return null;
        }

        // Read TRUE dimensions from the bytes we just downloaded. This
        // supersedes anything the markup claimed.
        $body = (string) $r->body();
        $info = @getimagesizefromstring($body);
        if (is_array($info) && ($info[0] ?? 0) > 0 && ($info[1] ?? 0) > 0) {
            $c['w'] = (int) $info[0];
            $c['h'] = (int) $info[1];
        }

        // Drop anything smaller than the floor or with no readable dims.
        if (! $c['w'] || ! $c['h']) return null;
        if ($c['w'] < self::MIN_WIDTH || $c['h'] < self::MIN_HEIGHT) return null;

        $c['bytes']  = $fullBytes;
        $c['ratio']  = round($c['w'] / max(1, $c['h']), 3);
        $c['bucket'] = self::bucketFor($c['w'], $c['h']);
        return $c;
    }

    /**
     * Parse the full image size from response headers, preferring
     * Content-Range (servers that honoured our Range request) over
     * Content-Length (servers that returned the whole image anyway).
     */
    private function fullSizeFromHeaders($response): ?int
    {
        $range = (string) $response->header('Content-Range');
        if ($range !== '' && preg_match('#/(\d+)\s*$#', $range, $m)) {
            return (int) $m[1];
        }
        $len = (int) ($response->header('Content-Length') ?: 0);
        return $len > 0 ? $len : null;
    }
}
