<?php

namespace App\Console\Commands;

use App\Models\BrandProfile;
use App\Services\FontMatchingService;
use App\Services\GeminiClient;
use App\Services\TemplateAdRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * End-to-end template QA against real brands. For each URL it extracts a real
 * brand profile (colors, fonts, logo, hero image, company) straight from the
 * live site, renders all 20 templates with that brand, screenshots each via the
 * renderer, and Gemini-vision-scores them. Artifacts land in
 * storage/app/template-qa-brands/<host>/ for human review.
 *
 *   php artisan templates:test-brands
 *   php artisan templates:test-brands --urls=https://a.com,https://b.com --rounds=1
 */
class TestBrandTemplatesCommand extends Command
{
    protected $signature = 'templates:test-brands
        {--urls= : Comma-separated brand URLs (defaults to a built-in set of 10)}
        {--rounds=1 : Vision-judge rounds per template}
        {--threshold=78 : Minimum passing score}
        {--no-judge : Render + screenshot only}';

    protected $description = 'Render + validate the ad templates against 10 real brands';

    private const DEFAULT_URLS = [
        'https://www.allbirds.com', 'https://www.glossier.com', 'https://www.gymshark.com',
        'https://www.casper.com', 'https://www.bombas.com', 'https://www.warbyparker.com',
        'https://www.notion.so', 'https://www.figma.com', 'https://stripe.com',
        'https://www.airbnb.com',
    ];

    public function handle(TemplateAdRenderer $renderer, FontMatchingService $fonts, GeminiClient $gemini): int
    {
        $threshold = (int) $this->option('threshold');
        $rounds    = max(1, (int) $this->option('rounds'));
        $judge     = ! $this->option('no-judge');

        $urls = $this->option('urls')
            ? array_filter(array_map('trim', explode(',', $this->option('urls'))))
            : self::DEFAULT_URLS;

        $base = storage_path('app/template-qa-brands');
        @mkdir($base, 0775, true);

        $summary = [];

        foreach ($urls as $url) {
            $host = parse_url($url, PHP_URL_HOST) ?: $url;
            $this->info("\n=== {$host} ===");

            $html = $this->fetch($url);
            if ($html === null) {
                $this->warn("  could not fetch {$url}, skipping");
                continue;
            }

            $brand = $this->buildBrand($url, $html, $fonts);
            $this->line(sprintf('  company=%s  colors=%s/%s/%s  fonts=%s/%s  logo=%s  hero=%s',
                $brand->company_name,
                $brand->primaryColor(), $brand->accentColor(), $brand->secondaryColor(),
                $brand->headlineFont(), $brand->bodyFont(),
                $brand->logoAsset ? 'yes' : ($brand->fonts_json['_logo_url'] ?? null ? 'yes' : 'no'),
                $brand->fonts_json['_hero_url'] ?? null ? 'yes' : 'no',
            ));

            $dir = $base . '/' . $host;
            @mkdir($dir, 0775, true);

            $scores = [];
            foreach ($renderer->ids() as $id) {
                $tpl = $renderer->template($id);
                $content = $this->contentFor($tpl, $brand);
                try {
                    $rendered = $renderer->render($id, $brand, $content);
                } catch (\Throwable $e) {
                    $this->warn("  render {$id} failed: " . $e->getMessage());
                    continue;
                }
                $png = $this->screenshot($rendered['html'], $rendered['width'], $rendered['height']);
                if ($png !== null) {
                    file_put_contents($dir . '/' . $id . '.png', $png);
                }
                if ($judge && $png !== null) {
                    $v = $this->judge($gemini, $this->downscale($png, 1100), $tpl, $rounds);
                    if ($v['score'] !== null) {
                        $scores[$id] = $v['score'];
                    }
                }
            }

            if ($judge && $scores) {
                $avg  = array_sum($scores) / count($scores);
                $low  = collect($scores)->sort()->take(3)->map(fn ($s, $k) => "{$k}:{$s}")->implode(', ');
                $summary[$host] = ['avg' => round($avg, 1), 'min' => min($scores), 'low' => $low];
                $this->line(sprintf('  avg=%.1f  min=%d  lowest: %s', $avg, min($scores), $low));
            }
        }

        if ($summary) {
            $this->newLine();
            $rows = [];
            foreach ($summary as $host => $s) {
                $rows[] = [$host, $s['avg'], $s['min'], ($s['min'] >= $threshold ? '✓' : '✗'), $s['low']];
            }
            $this->table(['brand', 'avg', 'min', 'ok', 'lowest 3'], $rows);
        }
        $this->info("Artifacts: {$base}");
        return self::SUCCESS;
    }

    /** Build a real-ish BrandProfile from the live homepage. */
    private function buildBrand(string $url, string $html, FontMatchingService $fonts): BrandProfile
    {
        $origin = $this->origin($url);
        $css    = $fonts->stylesheetsFor($html, $url);
        [$primary, $accent, $secondary] = $this->colors($html, $css, $url);
        $fontInfo = $fonts->detect($url);

        $brand = new BrandProfile();
        $brand->forceFill([
            'company_name'         => $this->company($html, $url),
            'website_url'          => $url,
            'colors_json'          => ['primary' => $primary, 'accent' => $accent, 'secondary' => $secondary],
            'visual_identity_json' => [],
            'fonts_json'           => array_merge($fontInfo, [
                '_logo_url' => $this->logo($html, $origin),
                '_hero_url' => $this->hero($html, $origin),
            ]),
        ]);
        return $brand;
    }

    private function contentFor(array $tpl, BrandProfile $brand): array
    {
        $name = $brand->company_name ?: 'Our brand';
        [$headline, $sub] = $this->copy($tpl, $name);
        return [
            'headline'    => $headline,
            'subheadline' => $sub,
            'cta'         => $tpl['width'] <= 320 && $tpl['height'] <= 100 ? 'Shop' : 'Discover more',
            'logo_url'    => $brand->fonts_json['_logo_url'] ?? null,
            'image_url'   => $tpl['needs_image'] ? ($brand->fonts_json['_hero_url'] ?? null) : null,
            'price'       => $tpl['kind'] === 'product' ? '$79' : null,
        ];
    }

    /** @return array{0:string,1:string} */
    private function copy(array $tpl, string $name): array
    {
        $h = $tpl['height'];
        if ($tpl['kind'] === 'product') return ["{$name} Signature Pick", ''];
        if ($h <= 50)  return ["Discover {$name}", ''];
        if ($h <= 110) return ["Meet {$name}", ''];
        if ($tpl['width'] * $h < 90000) return ["Designed for the way you live", "Discover {$name}."];
        return ["Designed for the way you live", "Discover {$name} — crafted to last."];
    }

    // --- live-site extraction helpers ---

    private function fetch(string $url): ?string
    {
        try {
            $r = Http::withHeaders([
                'User-Agent'      => 'Mozilla/5.0 (compatible; LayoutAIBot/1.0; +https://layout.ai/bot)',
                'Accept'          => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])->timeout(20)->get($url);
            return $r->successful() ? $r->body() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function company(string $html, string $url): string
    {
        if (preg_match('#<meta[^>]+property=["\']og:site_name["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
            return trim(html_entity_decode($m[1]));
        }
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $t = trim(html_entity_decode(strip_tags($m[1])));
            // Take the segment before a separator — usually the brand name.
            $t = preg_split('#[|\x{2013}\x{2014}\-:]#u', $t)[0] ?? $t;
            if (mb_strlen(trim($t)) >= 2) return trim($t);
        }
        return ucfirst(preg_replace('#^www\.#', '', parse_url($url, PHP_URL_HOST) ?: 'Brand'));
    }

    /** @return array{0:string,1:string,2:string} primary, accent, secondary hex */
    private function colors(string $html, string $css, string $url): array
    {
        $primary = null;
        // theme-color / tile-color, but ONLY if it's a vivid brand color — sites
        // commonly set these to their near-white/near-black page chrome.
        if (preg_match('#<meta[^>]+name=["\']theme-color["\'][^>]+content=["\']([^"\']+)#i', $html, $m)
            || preg_match('#<meta[^>]+name=["\']msapplication-TileColor["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
            $hex = $this->normHex(trim($m[1]));
            if ($hex && $this->isVivid($hex)) {
                $primary = $hex;
            }
        }
        // Most-common vivid hex across the HTML + fetched stylesheets.
        $primary ??= $this->dominantHex($html . "\n" . $css);
        // Last resort: a stable vivid color seeded from the domain so each brand
        // still looks distinct and intentional (never a shared default blue).
        $primary ??= $this->seedColor($url);
        return [$primary, $this->shift($primary, 28), '#0B1220'];
    }

    private function isVivid(string $hex): bool
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
        $max = max($r, $g, $b); $min = min($r, $g, $b);
        $sat = $max === 0 ? 0 : ($max - $min) / $max;
        $lum = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
        return $sat >= 0.30 && $lum >= 0.12 && $lum <= 0.78;
    }

    private function seedColor(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $hue  = hexdec(substr(md5($host), 0, 2)) / 255 * 360; // stable 0-360
        return $this->shift('#2563EB', (int) round($hue));    // rotate the base blue
    }

    private function dominantHex(string $html): ?string
    {
        if (! preg_match_all('/#([0-9a-fA-F]{6})\b/', $html, $m)) {
            return null;
        }
        $counts = [];
        foreach ($m[1] as $hex) {
            $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
            $max = max($r, $g, $b); $min = min($r, $g, $b);
            $sat = $max === 0 ? 0 : ($max - $min) / $max;
            $lum = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
            // Skip near-greys, near-white and near-black.
            if ($sat < 0.35 || $lum > 0.92 || $lum < 0.08) continue;
            $counts['#' . strtoupper($hex)] = ($counts['#' . strtoupper($hex)] ?? 0) + 1;
        }
        if (! $counts) return null;
        arsort($counts);
        return array_key_first($counts);
    }

    private function logo(string $html, string $origin): ?string
    {
        // apple-touch-icon is usually the square brand mark; prefer it.
        if (preg_match('#<link[^>]+rel=["\'][^"\']*apple-touch-icon[^"\']*["\'][^>]+href=["\']([^"\']+)#i', $html, $m)
            || preg_match('#<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\'][^"\']*apple-touch-icon#i', $html, $m)) {
            return $this->abs($m[1], $origin);
        }
        if (preg_match('#<meta[^>]+property=["\']og:logo["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
            return $this->abs($m[1], $origin);
        }
        return null;
    }

    private function hero(string $html, string $origin): ?string
    {
        if (preg_match('#<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
            return $this->abs($m[1], $origin);
        }
        return null;
    }

    private function origin(string $url): string
    {
        $p = parse_url($url);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    }

    private function abs(string $u, string $origin): string
    {
        $u = trim(html_entity_decode($u));
        if (preg_match('#^https?://#i', $u)) return $u;
        if (str_starts_with($u, '//')) return 'https:' . $u;
        if (str_starts_with($u, '/')) return $origin . $u;
        return $origin . '/' . ltrim($u, './');
    }

    private function normHex(string $v): ?string
    {
        $v = ltrim(trim($v), '#');
        if (strlen($v) === 3) $v = $v[0].$v[0].$v[1].$v[1].$v[2].$v[2];
        return preg_match('/^[0-9a-fA-F]{6}$/', $v) ? '#' . strtoupper($v) : null;
    }

    /** Rotate a hex color's hue by $deg for a complementary accent. */
    private function shift(string $hex, int $deg): string
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)) / 255; $g = hexdec(substr($hex, 2, 2)) / 255; $b = hexdec(substr($hex, 4, 2)) / 255;
        $max = max($r, $g, $b); $min = min($r, $g, $b); $d = $max - $min; $l = ($max + $min) / 2;
        $h = 0; $s = 0;
        if ($d != 0) {
            $s = $d / (1 - abs(2 * $l - 1));
            $h = match (true) {
                $max == $r => fmod((($g - $b) / $d), 6),
                $max == $g => (($b - $r) / $d) + 2,
                default    => (($r - $g) / $d) + 4,
            } * 60;
            if ($h < 0) $h += 360;
        }
        $h = fmod($h + $deg, 360);
        $c = (1 - abs(2 * $l - 1)) * $s; $x = $c * (1 - abs(fmod($h / 60, 2) - 1)); $mm = $l - $c / 2;
        [$r2, $g2, $b2] = match (true) {
            $h < 60  => [$c, $x, 0], $h < 120 => [$x, $c, 0], $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c], $h < 300 => [$x, 0, $c], default => [$c, 0, $x],
        };
        return sprintf('#%02X%02X%02X', (int) round(($r2 + $mm) * 255), (int) round(($g2 + $mm) * 255), (int) round(($b2 + $mm) * 255));
    }

    // --- shared render/judge helpers (mirrors ValidateTemplatesCommand) ---

    private function screenshot(string $html, int $w, int $h): ?string
    {
        $url = rtrim((string) config('services.renderer.url'), '/') . '/render';
        try {
            $resp = Http::timeout(60)->post($url, ['html' => $html, 'width' => $w, 'height' => $h, 'format' => 'png']);
            return $resp->successful() ? $resp->body() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function judge(GeminiClient $gemini, string $png, array $tpl, int $rounds): array
    {
        $schema = ['type' => 'object', 'properties' => [
            'score'  => ['type' => 'integer'],
            'issues' => ['type' => 'array', 'items' => ['type' => 'string']],
        ], 'required' => ['score', 'issues']];
        $prompt = "Strict art director: score this {$tpl['width']}x{$tpl['height']} display ad 0-100 on "
            . "production quality. Penalise clipped/overflowing text, illegible contrast, cut-off or "
            . "invisible logo, overlapping elements, broken image areas, fonts not rendering. "
            . 'Return JSON {"score":int,"issues":[short strings]}.';
        $best = ['score' => null, 'issues' => []];
        for ($i = 0; $i < $rounds; $i++) {
            $out = $gemini->generateJsonWithImages($prompt, [['bytes' => $png, 'mime' => 'image/png']], $schema, null, 40);
            if (is_array($out) && isset($out['score']) && ($best['score'] === null || $out['score'] > $best['score'])) {
                $best = ['score' => (int) $out['score'], 'issues' => array_map('strval', $out['issues'] ?? [])];
            }
        }
        return $best;
    }

    private function downscale(string $png, int $max): string
    {
        if (! function_exists('imagecreatefromstring')) return $png;
        $img = @imagecreatefromstring($png);
        if (! $img) return $png;
        $w = imagesx($img); $h = imagesy($img); $long = max($w, $h);
        if ($long <= $max) { imagedestroy($img); return $png; }
        $s = imagescale($img, (int) round($w * $max / $long), (int) round($h * $max / $long));
        imagedestroy($img);
        if (! $s) return $png;
        ob_start(); imagepng($s); $out = (string) ob_get_clean(); imagedestroy($s);
        return $out !== '' ? $out : $png;
    }
}
