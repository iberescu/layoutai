<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Detects the typefaces a brand uses and maps them to the closest free
 * Google Fonts so generated ads echo the brand's real typography.
 *
 * Signal priority (highest first):
 *   1. Google Fonts <link> already on the site  → exact family names.
 *   2. @font-face family names in the page CSS.
 *   3. font-family declarations in <style>/inline styles (frequency-ranked).
 *   4. Gemini vision inference from the logo (when the above are thin).
 *
 * Detected names are mapped via config/google_fonts_map.php (passthrough /
 * aliases), then a Gemini text suggestion, then generic-family defaults.
 */
class FontMatchingService
{
    /** CSS generic families that are not real typefaces. */
    private const GENERICS = [
        'sans-serif', 'serif', 'monospace', 'cursive', 'fantasy', 'system-ui',
        'ui-sans-serif', 'ui-serif', 'ui-monospace', 'ui-rounded', 'inherit',
        'initial', 'unset', 'revert', 'emoji', 'math', 'fangsong', 'auto', 'none',
    ];

    public function __construct(private GeminiClient $gemini)
    {
    }

    /**
     * @param  string       $websiteUrl Brand homepage.
     * @param  string|null  $logoBytes  Raw logo image bytes (optional, for vision).
     * @param  string|null  $logoMime   Logo mime type.
     * @return array{primary:?string,secondary:?string,google_primary:string,google_secondary:?string,google_link:string,source:string}
     */
    public function detect(string $websiteUrl, ?string $logoBytes = null, ?string $logoMime = null): array
    {
        $detected = [];
        $source   = 'default';

        $html = $this->fetchHtml($websiteUrl);
        if ($html !== null) {
            // Most modern sites declare fonts in external CSS, not inline — pull
            // a few same-origin stylesheets and parse them alongside the HTML.
            $css = $this->stylesheetsFor($html, $websiteUrl);
            $detected = $this->fromHtml($html . "\n" . $css);
            if (! empty($detected)) {
                $source = 'website';
            }
        }

        // Logo inference when CSS gave us nothing useful.
        if (empty($detected) && $logoBytes) {
            $inferred = $this->fromLogo($logoBytes, $logoMime ?? 'image/png');
            if (! empty($inferred)) {
                $detected = $inferred;
                $source   = 'logo';
            }
        }

        $primary   = $detected[0] ?? null;
        $secondary = $detected[1] ?? null;

        $googlePrimary   = $this->mapToGoogle($primary, 'sans-serif');
        $googleSecondary = $secondary ? $this->mapToGoogle($secondary, 'sans-serif') : null;
        if ($googleSecondary && Str::lower($googleSecondary) === Str::lower($googlePrimary)) {
            $googleSecondary = null; // collapse duplicates
        }

        return [
            'primary'          => $primary,
            'secondary'        => $secondary,
            'google_primary'   => $googlePrimary,
            'google_secondary' => $googleSecondary,
            'google_link'      => $this->buildLink(array_filter([$googlePrimary, $googleSecondary])),
            'source'           => $source,
        ];
    }

    /** Fetch homepage HTML + a little inline CSS for parsing. */
    private function fetchHtml(string $url): ?string
    {
        try {
            $resp = Http::withHeaders([
                'User-Agent'      => 'Mozilla/5.0 (compatible; LayoutAIBot/1.0; +https://layout.ai/bot)',
                'Accept'          => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])->timeout(15)->get($url);

            return $resp->successful() ? $resp->body() : null;
        } catch (\Throwable $e) {
            Log::info('FontMatching fetch failed for ' . $url . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch up to 3 same-origin stylesheets referenced in the HTML and return
     * their concatenated contents (capped) for font/color parsing.
     */
    public function stylesheetsFor(string $html, string $pageUrl): string
    {
        $base = parse_url($pageUrl);
        if (! $base || empty($base['host'])) {
            return '';
        }
        $origin  = ($base['scheme'] ?? 'https') . '://' . $base['host'];
        $resolve = function (string $href) use ($origin, $base): ?string {
            $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
            if ($href === '' || str_starts_with($href, 'data:')) return null;
            if (preg_match('#^https?://#i', $href)) return $href;
            if (str_starts_with($href, '//')) return ($base['scheme'] ?? 'https') . ':' . $href;
            if (str_starts_with($href, '/'))  return $origin . $href;
            return $origin . '/' . ltrim($href, './');
        };

        $hrefs = [];
        if (preg_match_all('#<link\b[^>]*rel=["\']?stylesheet["\']?[^>]*>#i', $html, $links)) {
            foreach ($links[0] as $tag) {
                if (preg_match('#href=["\']([^"\']+)["\']#i', $tag, $hm)) {
                    $u = $resolve($hm[1]);
                    // Skip cross-origin Google Fonts CSS (the <link> family is
                    // already parsed from the HTML); keep same-origin app CSS.
                    if ($u && ! str_contains($u, 'fonts.googleapis.com')) {
                        $hrefs[] = $u;
                    }
                }
            }
        }
        // Prefer same-origin sheets; cap at 3 to stay fast.
        $hrefs = array_values(array_unique(array_filter(
            $hrefs,
            fn ($u) => str_contains($u, (string) $base['host'])
        )));
        $hrefs = array_slice($hrefs, 0, 3);

        $css = '';
        foreach ($hrefs as $u) {
            try {
                $resp = Http::timeout(10)->get($u);
                if ($resp->successful()) {
                    $css .= "\n" . mb_substr($resp->body(), 0, 200000);
                }
            } catch (\Throwable $e) {
                // best-effort
            }
            if (mb_strlen($css) > 400000) {
                break;
            }
        }

        return $css;
    }

    /**
     * Extract candidate font family names from HTML, most-relevant first.
     *
     * @return array<int,string>
     */
    public function fromHtml(string $html): array
    {
        $ranked = []; // name => score

        $add = function (string $name, int $score) use (&$ranked) {
            $name = $this->cleanFamily($name);
            if ($name === '' || in_array(Str::lower($name), self::GENERICS, true)) {
                return;
            }
            $key = Str::lower($name);
            $ranked[$key] = ['name' => $name, 'score' => ($ranked[$key]['score'] ?? 0) + $score];
        };

        // 1. Google Fonts <link href="...css?family=Foo+Bar|Baz"> — strongest signal.
        if (preg_match_all('#fonts\.googleapis\.com/css2?\?([^"\'\s>]+)#i', $html, $m)) {
            foreach ($m[1] as $query) {
                $query = html_entity_decode($query, ENT_QUOTES | ENT_HTML5);
                // css?family=Foo+Bar|Baz   and   css2?family=Foo+Bar&family=Baz
                if (preg_match_all('#family=([^&|:]+)#i', $query, $fm)) {
                    foreach ($fm[1] as $fam) {
                        $add(str_replace('+', ' ', $fam), 100);
                    }
                }
            }
        }

        // 2. @font-face { font-family: "Foo"; }
        if (preg_match_all('#@font-face\b[^}]*?font-family\s*:\s*([^;]+);#is', $html, $m)) {
            foreach ($m[1] as $fam) {
                $add($this->firstFamily($fam), 60);
            }
        }

        // 3. font-family declarations in <style> blocks and inline style="".
        if (preg_match_all('#font-family\s*:\s*([^;}"\']+)#i', $html, $m)) {
            foreach ($m[1] as $decl) {
                $add($this->firstFamily($decl), 8);
            }
        }

        // Bias headings: anything inside an h1/h2 inline style counts double.
        if (preg_match_all('#<h[12][^>]*style=["\'][^"\']*font-family\s*:\s*([^;"\']+)#i', $html, $m)) {
            foreach ($m[1] as $decl) {
                $add($this->firstFamily($decl), 25);
            }
        }

        uasort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_values(array_map(fn ($r) => $r['name'], $ranked));
    }

    /** Ask Gemini to name the typeface(s) used in the logo. */
    private function fromLogo(string $bytes, string $mime): array
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'fonts' => [
                    'type'  => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['fonts'],
        ];
        $prompt = 'You are a typography expert. Look at this brand logo and name the 1-2 '
            . 'font families that most closely match the lettering (prefer well-known typeface '
            . 'names, e.g. "Futura", "Helvetica", "Garamond"). If the logo has no text, return an '
            . 'empty array. Respond as JSON {"fonts": ["Name", ...]}.';

        $out = $this->gemini->generateJsonWithImages($prompt, [['bytes' => $bytes, 'mime' => $mime]], $schema, null, 30);
        $fonts = array_values(array_filter(array_map(
            fn ($f) => $this->cleanFamily((string) $f),
            $out['fonts'] ?? []
        )));

        return $fonts;
    }

    /** Map a detected family name to the closest Google Font. */
    public function mapToGoogle(?string $name, string $genericFallback = 'sans-serif'): string
    {
        $map = config('google_fonts_map');
        if (! $name) {
            return $map['defaults'][$genericFallback] ?? $map['defaults']['fallback'];
        }
        $key = Str::lower($this->cleanFamily($name));

        if (in_array($key, $map['passthrough'], true)) {
            return Str::title($key) === 'Dm Sans' ? 'DM Sans' : $this->titleFamily($key);
        }
        if (isset($map['aliases'][$key])) {
            return $map['aliases'][$key];
        }

        // Unknown: ask Gemini for the closest Google Font (cached per-name).
        $suggested = $this->geminiClosest($name);
        if ($suggested) {
            return $suggested;
        }

        return $map['defaults'][$genericFallback] ?? $map['defaults']['fallback'];
    }

    private function geminiClosest(string $name): ?string
    {
        if (! $this->gemini->isConfigured()) {
            return null;
        }
        // Constrain the choice to families we KNOW exist on Google Fonts, so the
        // resulting <link> always loads (no echoing back a proprietary name like
        // "Maison Neue" that would 404). The passthrough list is our catalog.
        $catalog = collect((array) config('google_fonts_map.passthrough'))
            ->map(fn ($f) => $this->titleFamily($f))->values()->all();

        $schema = [
            'type'       => 'object',
            'properties' => ['google_font' => ['type' => 'string', 'enum' => $catalog]],
            'required'   => ['google_font'],
        ];
        $out = $this->gemini->generateJson(
            "Pick the single closest match to the typeface \"{$name}\" from this list of Google Fonts. "
            . 'Consider classification (sans/serif/slab/mono/display) and weight/feel. '
            . 'Return JSON {"google_font": "ExactNameFromList"}. List: ' . implode(', ', $catalog),
            $schema,
            null,
            20
        );
        $font = isset($out['google_font']) ? $this->cleanFamily((string) $out['google_font']) : '';

        // Only accept an answer that's actually in our catalog (case-insensitive).
        $match = collect($catalog)->first(fn ($c) => Str::lower($c) === Str::lower($font));

        return $match ?: null;
    }

    private function buildLink(array $families): string
    {
        $families = array_values(array_unique(array_filter($families)));
        if (empty($families)) {
            return '';
        }
        $query = collect($families)
            ->map(fn (string $f) => 'family=' . str_replace(' ', '+', $f) . ':wght@400;600;700;800')
            ->implode('&');

        return '<link rel="preconnect" href="https://fonts.googleapis.com">'
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link href="https://fonts.googleapis.com/css2?' . $query . '&display=swap" rel="stylesheet">';
    }

    /** First family in a comma-separated font-family stack. */
    private function firstFamily(string $decl): string
    {
        $parts = explode(',', $decl);
        return $this->cleanFamily($parts[0] ?? '');
    }

    /** Strip quotes, !important, var() noise, and weight suffixes. */
    private function cleanFamily(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('#!important#i', '', $name) ?? $name;
        $name = trim($name, " \t\n\r\0\x0B\"'`");
        // Drop var(--x) and url(...) garbage.
        if (Str::startsWith(Str::lower($name), ['var(', 'url(', 'http'])) {
            return '';
        }
        // Drop obvious weight/style words attached to a family.
        $name = preg_replace('#\b(thin|extralight|light|regular|medium|semibold|bold|extrabold|black|italic|oblique)\b#i', '', $name) ?? $name;
        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    private function titleFamily(string $lower): string
    {
        // Preserve known mixed-case Google names.
        $special = [
            'pt sans' => 'PT Sans', 'pt serif' => 'PT Serif', 'dm sans' => 'DM Sans',
            'dm serif display' => 'DM Serif Display', 'eb garamond' => 'EB Garamond',
            'ibm plex sans' => 'IBM Plex Sans', 'ibm plex serif' => 'IBM Plex Serif',
            'ibm plex mono' => 'IBM Plex Mono',
        ];
        return $special[$lower] ?? Str::title($lower);
    }
}
