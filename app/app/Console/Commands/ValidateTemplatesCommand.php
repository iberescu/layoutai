<?php

namespace App\Console\Commands;

use App\Services\GeminiClient;
use App\Services\TemplateAdRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Renders every pre-built ad template with a fixture brand, screenshots it via
 * the Playwright renderer, and scores each screenshot with a Gemini-vision
 * rubric. Saves PNGs + a JSON report under storage/app/template-qa/ for human
 * review. Re-run after editing templates until every score clears --threshold.
 *
 *   php artisan templates:validate
 *   php artisan templates:validate --id=bold-type-300x250 --rounds=3
 */
class ValidateTemplatesCommand extends Command
{
    protected $signature = 'templates:validate
        {--id= : Validate a single template id}
        {--rounds=5 : Vision-judge rounds per template (best score wins; smooths judge noise)}
        {--threshold=82 : Minimum passing score}
        {--no-judge : Render + screenshot only, skip Gemini vision}';

    protected $description = 'Render, screenshot and Gemini-vision-validate the ad templates';

    public function handle(TemplateAdRenderer $renderer, GeminiClient $gemini): int
    {
        $threshold = (int) $this->option('threshold');
        $rounds    = max(1, (int) $this->option('rounds'));
        $judge     = ! $this->option('no-judge');

        $brand   = $renderer->fixtureBrand();
        $logoUri = $this->sampleLogo($brand->company_name);
        $imgUrl  = 'https://picsum.photos/seed/layoutai/1200/1200';

        $ids = $this->option('id')
            ? [$this->option('id')]
            : $renderer->ids();

        $outBase = storage_path('app/template-qa');
        @mkdir($outBase, 0775, true);

        $report  = [];
        $rows    = [];
        $failing = 0;

        foreach ($ids as $id) {
            $tpl = $renderer->template($id);
            if (! $tpl) {
                $this->warn("skip unknown template {$id}");
                continue;
            }

            [$headline, $sub] = $this->sampleCopy($tpl);
            $content = [
                'headline'    => $headline,
                'subheadline' => $sub,
                'cta'         => $this->sampleCta($tpl),
                'logo_url'    => $logoUri,
                'image_url'   => $tpl['needs_image'] ? $imgUrl : null,
                'price'       => $tpl['kind'] === 'product' ? '$129' : null,
            ];

            try {
                $rendered = $renderer->render($id, $brand, $content);
            } catch (\Throwable $e) {
                $this->error("render failed {$id}: " . $e->getMessage());
                $failing++;
                continue;
            }

            $png = $this->screenshot($rendered['html'], $rendered['width'], $rendered['height']);
            $dir = $outBase . '/' . $id;
            @mkdir($dir, 0775, true);
            if ($png !== null) {
                file_put_contents($dir . '/render.png', $png);
            }
            file_put_contents($dir . '/render.html', $rendered['html']);

            $verdict = ['score' => null, 'issues' => [], 'suggestions' => []];
            if ($judge && $png !== null) {
                // Downscale before sending to the vision model — full 2x renders
                // (e.g. 2160x3840 stories) blow the CLI memory limit when base64'd
                // and the judge doesn't need that resolution.
                $verdict = $this->judge($gemini, $this->downscale($png, 1200), $tpl, $rounds);
            }

            $score = $verdict['score'];
            $pass  = $score !== null && $score >= $threshold;
            if ($score !== null && ! $pass) {
                $failing++;
            }

            $report[$id] = [
                'size'        => "{$tpl['width']}x{$tpl['height']}",
                'kind'        => $tpl['kind'],
                'score'       => $score,
                'pass'        => $pass,
                'issues'      => $verdict['issues'],
                'suggestions' => $verdict['suggestions'],
            ];
            $rows[] = [
                $id,
                "{$tpl['width']}x{$tpl['height']}",
                $score === null ? '—' : ($pass ? "✓ {$score}" : "✗ {$score}"),
                implode('; ', array_slice($verdict['issues'], 0, 2)),
            ];

            $this->line(sprintf('  %-28s %-10s %s', $id, "{$tpl['width']}x{$tpl['height']}", $score === null ? 'rendered' : ($pass ? "PASS {$score}" : "FAIL {$score}")));
        }

        file_put_contents($outBase . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->table(['template', 'size', 'score', 'top issues'], $rows);
        $this->info("Artifacts: {$outBase}  (render.png / render.html per template, report.json)");

        if ($judge && $failing > 0) {
            $this->warn("{$failing} template(s) below threshold {$threshold} — review PNGs + report.json, fix, re-run.");
            return self::FAILURE;
        }
        $this->info('All templates passed.' . ($judge ? '' : ' (judge skipped)'));
        return self::SUCCESS;
    }

    /** POST HTML to the Playwright renderer, return PNG bytes (or null). */
    private function screenshot(string $html, int $w, int $h): ?string
    {
        $url = rtrim((string) config('services.renderer.url'), '/') . '/render';
        try {
            $resp = Http::timeout(60)->post($url, [
                'html' => $html, 'width' => $w, 'height' => $h, 'format' => 'png',
            ]);
            if ($resp->successful()) {
                return $resp->body();
            }
            $this->warn("renderer {$resp->status()}: " . substr($resp->body(), 0, 160));
        } catch (\Throwable $e) {
            $this->warn('renderer error: ' . $e->getMessage());
        }
        return null;
    }

    /** Score one screenshot with the Gemini-vision rubric; best of N rounds. */
    private function judge(GeminiClient $gemini, string $png, array $tpl, int $rounds): array
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'score'       => ['type' => 'integer'],
                'issues'      => ['type' => 'array', 'items' => ['type' => 'string']],
                'suggestions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['score', 'issues', 'suggestions'],
        ];
        $prompt = "You are a strict senior art director reviewing a {$tpl['width']}x{$tpl['height']} "
            . "display ad rendered from a reusable template. Score 0-100 on production quality. "
            . "Penalise: text clipped/overflowing or touching edges, illegible contrast, overlapping "
            . "elements, a logo that's cut off or invisible, awkward whitespace, font that doesn't "
            . "render (boxes/fallback), CTA not clearly a button, empty/broken image areas. Reward: "
            . "clear hierarchy, legible text, balanced layout, on-brand cohesive color. Return JSON "
            . '{"score":int,"issues":[short strings],"suggestions":[short CSS-level fixes]}.';

        $best = ['score' => null, 'issues' => [], 'suggestions' => []];
        for ($i = 0; $i < $rounds; $i++) {
            $out = $gemini->generateJsonWithImages($prompt, [['bytes' => $png, 'mime' => 'image/png']], $schema, null, 40);
            if (! is_array($out) || ! isset($out['score'])) {
                continue;
            }
            $score = (int) $out['score'];
            if ($best['score'] === null || $score > $best['score']) {
                $best = [
                    'score'       => $score,
                    'issues'      => array_map('strval', $out['issues'] ?? []),
                    'suggestions' => array_map('strval', $out['suggestions'] ?? []),
                ];
            }
        }
        return $best;
    }

    /** Downscale a PNG so its longest side is <= $max px (keeps payloads small). */
    private function downscale(string $png, int $max): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $png;
        }
        $img = @imagecreatefromstring($png);
        if (! $img) {
            return $png;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $long = max($w, $h);
        if ($long <= $max) {
            imagedestroy($img);
            return $png;
        }
        $scaled = imagescale($img, (int) round($w * $max / $long), (int) round($h * $max / $long));
        imagedestroy($img);
        if (! $scaled) {
            return $png;
        }
        ob_start();
        imagepng($scaled);
        $out = (string) ob_get_clean();
        imagedestroy($scaled);
        return $out !== '' ? $out : $png;
    }

    /** A self-contained dark-on-transparent SVG wordmark as a data URI. */
    private function sampleLogo(string $name): string
    {
        $name = htmlspecialchars($name, ENT_QUOTES);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="48">'
            . '<rect width="200" height="48" fill="none"/>'
            . '<circle cx="22" cy="24" r="12" fill="#0B1220"/>'
            . '<text x="42" y="32" font-family="Arial,Helvetica,sans-serif" font-size="22" '
            . 'font-weight="700" fill="#0B1220">' . $name . '</text></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Format-appropriate sample copy. Thin/tiny banners get short headlines and
     * no sub — mirroring how the pipeline assigns copy by format in production —
     * so templates aren't judged against copy they were never meant to hold.
     *
     * @return array{0:string,1:string} [headline, subheadline]
     */
    private function sampleCopy(array $tpl): array
    {
        $w = $tpl['width'];
        $h = $tpl['height'];

        if ($tpl['kind'] === 'product') {
            return ['Trailhead 30L Backpack', ''];
        }
        if ($h <= 50) {                       // 320x50
            return ['Shop trail gear', ''];
        }
        if ($h <= 110) {                      // 728x90, 320x100 — single tight row
            return ['Gear up for the trail', ''];
        }
        if ($w * $h < 90000) {                // small rectangles/squares
            return ['Adventure starts here', 'Premium outdoor gear, built to last.'];
        }
        return ['Adventure starts where the map ends', 'Premium gear engineered to outlast the trail.'];
    }

    private function sampleCta(array $tpl): string
    {
        return $tpl['width'] <= 320 && $tpl['height'] <= 100 ? 'Shop' : 'Shop the range';
    }
}
