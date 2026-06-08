<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Generates Facebook/Instagram ad-creative variants for the "$500 free ads
 * credit" campaign via the Gemini image model, using ads/ad.png as a style
 * reference (image-to-image). 9:16 vertical, on-brand (navy→blue/purple),
 * exact offer copy + Claim CTA. Saves PNGs to storage/app/ad-variants/.
 *
 *   php artisan ads:variants
 *   php artisan ads:variants --only=v03 --model=gemini-2.5-flash-image
 */
class GenerateAdVariantsCommand extends Command
{
    protected $signature = 'ads:variants
        {--base=ads/ad.png : Reference creative (relative to repo root / app cwd)}
        {--model=gemini-2.5-flash-image : Gemini image model}
        {--only= : Generate a single variant key}';

    protected $description = 'Generate 10 FB/IG ad-creative variants with Gemini (image-to-image)';

    public function handle(): int
    {
        $key = (string) config('services.gemini.api_key');
        if ($key === '') {
            $this->error('GEMINI_API_KEY not configured');
            return self::FAILURE;
        }
        $base  = rtrim((string) config('services.gemini.base'), '/');
        $model = (string) $this->option('model');
        $url   = "{$base}/models/{$model}:generateContent?key={$key}";

        // Reference image (the approved base ad) — passed inline for style/brand consistency.
        $basePath = base_path('../' . $this->option('base'));
        if (! is_file($basePath)) {
            $basePath = base_path($this->option('base'));         // fallback
        }
        $refData = is_file($basePath) ? base64_encode((string) file_get_contents($basePath)) : null;
        if (! $refData) {
            $this->warn("base creative not found at {$this->option('base')} — generating without a reference image");
        }

        $preamble = 'Design a high-converting VERTICAL 9:16 mobile ad for Facebook/Instagram for "layout.ai", a SaaS that '
            . 'auto-generates and tests display ads. Match the supplied reference for brand + style: deep navy background, '
            . 'blue-to-violet (#2563EB→#7C3AED) accents, bold modern geometric sans-serif (Inter-like), clean premium '
            . 'ad-agency quality. Include a clear CTA button reading "Claim My $500 Credit". Keep a small "layout.ai" wordmark. '
            . 'CRITICAL: spell every word EXACTLY as given, render text crisp and perfectly legible, do NOT garble, warp, '
            . 'duplicate, or invent text. Leave safe margins (no text near edges). Output a single polished ad image.';

        $variants = [
            'v01-classic'      => 'Concept: refined version of the reference. Headline "$500 FREE ADS CREDIT". Subtext "We generate 1,000 ads. We test them all. You get the winner." A confident male small-business owner, subtle rising chart behind.',
            'v02-stop-guess'   => 'Concept: headline "STOP GUESSING WHICH AD WORKS". Subtext "We build 1,000, test them all, you keep the winner — free." A focused founder at a laptop. Bold, punchy.',
            'v03-one-winner'   => 'Concept: huge typographic statement "1,000 ADS. 1 WINNER. FREE." Minimal imagery, mostly bold type on gradient, a small trophy/spark icon. Subtext "$500 ad credit to find yours."',
            'v04-woman-owner'  => 'Concept: a smiling female small-business owner in a shop. Headline "$500 TO FIND YOUR BEST AD". Subtext "1,000 generated, tested, ranked — winner is yours."',
            'v05-bold-type'    => 'Concept: pure typographic poster, no people. Enormous "$500 FREE" stacked, then "ADS CREDIT". Subtext "1,000 ads tested. You get the winner." Sparkle/AI accent.',
            'v06-dashboard'    => 'Concept: a glossy 3D results dashboard with rising ROI charts and KPI cards as the hero. Headline "ADS THAT ACTUALLY SELL". Subtext "Test 1,000. Keep the winner. $500 free."',
            'v07-funnel'       => 'Concept: a visual funnel showing 1,000 → 30 → 10 → 1 narrowing to a glowing "winner" ad. Headline "WE TEST 1,000. YOU GET THE WINNER." Subtext "$500 ad credit, on us."',
            'v08-urgency'      => 'Concept: urgency framing with a "LIMITED TIME" pill at top. Headline "$500 AD CREDIT". Subtext "Claim before it\'s gone. We build + test 1,000 ads for you." Energetic.',
            'v09-question'     => 'Concept: question hook headline "WHICH AD SELLS MORE?". Subtext "We\'ll find out — on us. 1,000 ads tested, $500 free." A curious business owner.',
            'v10-premium'      => 'Concept: minimal premium aesthetic, lots of negative space, a single elegant device/ad mockup floating. Headline "YOUR WINNING AD." Subtext "We find it from 1,000 — free $500 credit."',
        ];

        if ($only = $this->option('only')) {
            $match = collect(array_keys($variants))->first(fn ($k) => str_starts_with($k, $only));
            if ($match) {
                $variants = [$match => $variants[$match]];
            }
        }

        $dir = storage_path('app/ad-variants');
        @mkdir($dir, 0775, true);

        foreach ($variants as $name => $concept) {
            $this->line("→ {$name}");
            $parts = [['text' => $preamble . "\n\n" . $concept
                . "\n\nThe ad MUST be a tall VERTICAL 9:16 image (portrait, like a phone screen)."]];
            if ($refData) {
                $parts[] = ['inline_data' => ['mime_type' => 'image/png', 'data' => $refData]];
            }

            // The image model intermittently returns text-only — retry until we
            // get an image (or give up after a few attempts).
            $saved = false;
            for ($attempt = 1; $attempt <= 4 && ! $saved; $attempt++) {
                try {
                    $res = Http::timeout(120)->post($url, [
                        'contents' => [['parts' => $parts]],
                        'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']],
                    ]);
                } catch (\Throwable $e) {
                    $this->warn("  attempt {$attempt} exception: " . $e->getMessage());
                    continue;
                }
                if (! $res->successful()) {
                    $this->warn("  attempt {$attempt} HTTP {$res->status()}: " . substr($res->body(), 0, 160));
                    continue;
                }
                foreach (($res->json('candidates.0.content.parts') ?? []) as $part) {
                    $data = $part['inlineData']['data'] ?? $part['inline_data']['data'] ?? null;
                    if ($data) {
                        file_put_contents("{$dir}/{$name}.png", base64_decode($data));
                        $this->info("  saved {$name}.png (attempt {$attempt})");
                        $saved = true;
                        break;
                    }
                }
            }
            if (! $saved) {
                $this->warn('  no image after retries — skipped');
            }
        }

        $this->info("Artifacts: {$dir}");
        return self::SUCCESS;
    }
}
