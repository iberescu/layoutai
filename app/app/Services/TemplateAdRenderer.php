<?php

namespace App\Services;

use App\Models\BrandProfile;

/**
 * Fills a pre-built HTML ad template with brand variables — colors, matched
 * Google Fonts, logo, imagery and copy — deterministically (NO Gemini).
 *
 * Templates live in resources/ad-templates/*.html and use {{token}} slots.
 * The manifest (config/ad_templates.php) declares each template's size, kind
 * and whether it needs imagery. The renderer guarantees every token is filled
 * and strips any leftover {{...}} so output is always valid, self-contained
 * HTML clamped to the exact ad dimensions.
 */
class TemplateAdRenderer
{
    /** @var array<string,string> in-memory cache of template file contents */
    private array $cache = [];

    /** @var array<string,string> per-logo-url chip background cache */
    private array $chipCache = [];

    /**
     * @return array<int,array<string,mixed>> manifest rows
     */
    public function manifest(): array
    {
        return config('ad_templates', []);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function template(string $id): ?array
    {
        foreach ($this->manifest() as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    /** Template ids, optionally filtered by kind (brand|product) and image need. */
    public function ids(?string $kind = null, ?bool $needsImage = null): array
    {
        return collect($this->manifest())
            ->when($kind !== null, fn ($c) => $c->where('kind', $kind))
            ->when($needsImage !== null, fn ($c) => $c->where('needs_image', $needsImage))
            ->pluck('id')->values()->all();
    }

    /**
     * Render a template for a brand + content payload.
     *
     * @param array{headline?:string,subheadline?:string,cta?:string,image_url?:?string,price?:?string} $content
     * @return array{html:string,css:string,width:int,height:int}
     */
    public function render(string $templateId, BrandProfile $brand, array $content): array
    {
        $tpl = $this->template($templateId);
        if (! $tpl) {
            throw new \InvalidArgumentException("Unknown ad template: {$templateId}");
        }
        $w = (int) $tpl['width'];
        $h = (int) $tpl['height'];

        // When the brand's colors are missing/invalid (e.g. Gemini returned a
        // sentence instead of a hex), fall back to a colour seeded from the
        // brand's identity so each brand still looks distinct + intentional —
        // not a shared default blue.
        $primary   = $this->hex($brand->primaryColor(), $this->seededColor($brand, 0));
        $accent    = $this->hex($brand->accentColor(), $this->seededColor($brand, 42));
        $secondary = $this->hex($brand->secondaryColor(), '#0B1220');

        $logoUrl  = $content['logo_url'] ?? $this->logoUrl($brand);
        $imageUrl = $content['image_url'] ?? null;

        $vars = [
            'w'             => (string) $w,
            'h'             => (string) $h,
            'primary'       => $primary,
            'accent'        => $accent,
            'secondary'     => $secondary,
            // Readable text colors over primary / dark.
            'on_primary'    => $this->readableOn($primary),
            'on_accent'     => $this->readableOn($accent),
            // Logo chip: white normally, but dark when the logo is light/white
            // (else a white-on-transparent logo vanishes on a white chip).
            'chip_bg'       => $this->chipBg($logoUrl, $secondary),
            'headline_font' => $this->cssFamily($brand->headlineFont()),
            'body_font'     => $this->cssFamily($brand->bodyFont()),
            'fonts_link'    => $brand->googleFontsLink(),
            'company'       => e((string) ($brand->company_name ?: '')),
            'headline'      => e((string) ($content['headline'] ?? '')),
            'subheadline'   => e((string) ($content['subheadline'] ?? '')),
            'cta'           => e((string) ($content['cta'] ?? 'Learn more')),
            // Hero background: real image when available, else a brand gradient.
            'hero_css'      => $imageUrl
                ? "background:#0b1220 url('" . $this->url($imageUrl) . "') center/cover no-repeat;"
                : "background:linear-gradient(135deg, {$primary} 0%, {$accent} 100%);",
            // Self-hiding logo lockup — the chip wrapper only renders when a
            // logo exists, keeping it legible on any background.
            'logo_block'    => $logoUrl
                ? '<span class="logo"><img src="' . $this->url($logoUrl) . '" alt=""></span>'
                : '',
            'sub_block'     => ! empty($content['subheadline'])
                ? '<div class="sub">' . e((string) $content['subheadline']) . '</div>'
                : '',
            'price_block'   => ! empty($content['price'])
                ? '<div class="price">' . e((string) $content['price']) . '</div>'
                : '',
        ];

        $html = $this->load($tpl['file']);
        foreach ($vars as $k => $v) {
            $html = str_replace('{{' . $k . '}}', $v, $html);
        }
        // Strip any unfilled tokens so nothing leaks into the rendered ad.
        $html = preg_replace('/\{\{[a-z0-9_]+\}\}/i', '', $html) ?? $html;

        $html = $this->clamp($html, $w, $h);

        return ['html' => $html, 'css' => '', 'width' => $w, 'height' => $h];
    }

    /** Built-in fixture brand for standalone template QA (no DB row needed). */
    public function fixtureBrand(array $overrides = []): BrandProfile
    {
        $brand = new BrandProfile();
        $brand->forceFill(array_merge([
            'company_name'         => 'Northwind',
            'website_url'          => 'https://example.com',
            'industry'             => 'Outdoor gear',
            'description'          => 'Gear built for the long way round.',
            'colors_json'          => ['primary' => '#0F766E', 'accent' => '#F59E0B', 'secondary' => '#0B1220'],
            'visual_identity_json' => [],
            'fonts_json'           => [
                'google_primary'   => 'Space Grotesk',
                'google_secondary' => 'Inter',
            ],
            'ctas_json'            => ['Shop the range', 'Discover more'],
        ], $overrides));

        return $brand;
    }

    private function load(string $file): string
    {
        if (isset($this->cache[$file])) {
            return $this->cache[$file];
        }
        $path = resource_path('ad-templates/' . $file);
        if (! is_file($path)) {
            throw new \RuntimeException("Template file missing: {$file}");
        }
        return $this->cache[$file] = (string) file_get_contents($path);
    }

    /** Force the output to render at exactly w×h with no scrollbars. */
    private function clamp(string $html, int $w, int $h): string
    {
        $guard = "<style>html,body{margin:0;padding:0;width:{$w}px;height:{$h}px;overflow:hidden!important;}"
            . "*{box-sizing:border-box;}</style>";
        if (stripos($html, '</head>') !== false) {
            return preg_replace('#</head>#i', $guard . '</head>', $html, 1) ?? $html;
        }
        return $guard . $html;
    }

    private function logoUrl(BrandProfile $brand): ?string
    {
        try {
            $uploaded = $brand->logoAsset?->url();
            if ($uploaded) {
                return $uploaded;
            }
        } catch (\Throwable) {
            // fall through to crawl-extracted logo
        }
        // Crawl-extracted brand logo (BrandLogoExtractor), if any.
        return $brand->visual_identity_json['logo_url'] ?? null;
    }

    /** A stable, vivid brand colour seeded from the brand identity (+hue shift). */
    private function seededColor(BrandProfile $brand, int $shift): string
    {
        $seed = (string) ($brand->company_name ?: $brand->website_url ?: 'brand');
        $hue  = (int) ((hexdec(substr(md5($seed), 0, 2)) / 255 * 360) + $shift) % 360;
        return $this->hslHex($hue, 0.58, 0.46);
    }

    private function hslHex(float $h, float $s, float $l): string
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60  => [$c, $x, 0], $h < 120 => [$x, $c, 0], $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c], $h < 300 => [$x, 0, $c], default => [$c, 0, $x],
        };
        return sprintf('#%02X%02X%02X', (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
    }

    private function hex(?string $value, string $fallback): string
    {
        $value = trim((string) $value);
        return preg_match('/^#?[0-9a-fA-F]{6}$/', $value)
            ? '#' . ltrim($value, '#')
            : $fallback;
    }

    /**
     * Choose a logo-chip background. White for dark/colored logos; a dark chip
     * (brand secondary if it's dark, else near-black) for light/white logos so
     * they don't vanish. Fetches + samples the logo once per URL, then caches.
     */
    private function chipBg(?string $logoUrl, string $secondary): string
    {
        $white = '#FFFFFF';
        if (! $logoUrl) {
            return $white;
        }
        if (isset($this->chipCache[$logoUrl])) {
            return $this->chipCache[$logoUrl];
        }

        $dark = $this->isDark($secondary) ? $secondary : '#0B1220';
        $chip = $white;
        try {
            $bytes = $this->fetchBytes($logoUrl);
            if ($bytes !== null && function_exists('imagecreatefromstring')) {
                $img = @imagecreatefromstring($bytes);
                if ($img) {
                    if ($this->logoIsLight($img)) {
                        $chip = $dark;
                    }
                    imagedestroy($img);
                }
            }
        } catch (\Throwable) {
            // keep white
        }

        return $this->chipCache[$logoUrl] = $chip;
    }

    /** True when the logo's opaque pixels are mostly light (would disappear on white). */
    private function logoIsLight($img): bool
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $stepX = max(1, (int) ($w / 32));
        $stepY = max(1, (int) ($h / 32));
        $sum = 0.0; $count = 0;
        for ($y = 0; $y < $h; $y += $stepY) {
            for ($x = 0; $x < $w; $x += $stepX) {
                $rgba = imagecolorat($img, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;      // 0 opaque .. 127 transparent
                if ($alpha > 90) {
                    continue;                        // skip near-transparent pixels
                }
                $r = ($rgba >> 16) & 0xFF; $g = ($rgba >> 8) & 0xFF; $b = $rgba & 0xFF;
                $sum += (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
                $count++;
            }
        }
        if ($count === 0) {
            return false;                            // fully transparent → keep white chip
        }
        return ($sum / $count) > 0.62;
    }

    private function isDark(string $hex): bool
    {
        $hex = ltrim($hex, '#');
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return true;
        }
        $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
        return ((0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255) < 0.4;
    }

    private function fetchBytes(string $url): ?string
    {
        if (str_starts_with($url, 'data:')) {
            if (preg_match('#^data:[^;,]*;base64,(.*)$#s', $url, $m)) {
                return base64_decode($m[1]) ?: null;
            }
            return null; // non-base64 data URI (e.g. svg) — not GD-parseable anyway
        }
        try {
            $resp = \Illuminate\Support\Facades\Http::timeout(8)->get($url);
            return $resp->successful() ? $resp->body() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Pick black or white for legible text on the given background hex. */
    private function readableOn(string $hex): string
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        // Relative luminance (sRGB approximation).
        $lum = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
        return $lum > 0.6 ? '#0B1220' : '#FFFFFF';
    }

    private function cssFamily(string $family): string
    {
        $family = trim($family) ?: 'Inter';
        return "'{$family}', system-ui, -apple-system, Segoe UI, sans-serif";
    }

    private function url(string $url): string
    {
        // Single-quote safe for use inside url('...') and src="...".
        return str_replace(["'", '"', "\n", "\r"], ['%27', '%22', '', ''], trim($url));
    }
}
