<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Generates layout.ai logo concepts via the Gemini image model (Nano Banana).
 * On-brand: Inter-style geometric sans wordmark "layout.ai", blue→purple
 * gradient (#2563EB→#7C3AED), an AI mark, flat vector on white. Saves PNGs to
 * storage/app/logo-gen/ for review.
 *
 *   php artisan logo:generate
 *   php artisan logo:generate --model=gemini-2.5-flash-image --only=v1
 */
class GenerateLogoCommand extends Command
{
    protected $signature = 'logo:generate
        {--model=gemini-2.5-flash-image : Gemini image model}
        {--only= : Generate a single variant key}
        {--prompt= : Override with a custom prompt (saved as custom.png)}
        {--install= : Trim + install storage/app/logo-gen/<key>.png as public/img/logo.png}';

    protected $description = 'Generate layout.ai logo concepts with Gemini image generation';

    public function handle(): int
    {
        if ($this->option('install')) {
            return $this->install((string) $this->option('install'));
        }

        $key   = (string) config('services.gemini.api_key');
        if ($key === '') {
            $this->error('GEMINI_API_KEY not configured');
            return self::FAILURE;
        }
        $base  = rtrim((string) config('services.gemini.base'), '/');
        $model = (string) $this->option('model');
        $url   = "{$base}/models/{$model}:generateContent?key={$key}";

        $brand = 'The wordmark is the exact text "layout.ai" — all lowercase, one word including the period, '
            . 'set in a clean modern GEOMETRIC SANS-SERIF typeface like Inter, bold, tight letter-spacing. '
            . 'The lettering has a smooth left-to-right gradient from blue #2563EB to violet #7C3AED. '
            . 'Flat vector logo, crisp edges, no 3D, no photo, no mockup, on a PLAIN SOLID WHITE background, '
            . 'generous padding, horizontal lockup, premium SaaS software-brand quality, high resolution.';

        $prompts = [
            'v1-grid-spark' => "A minimalist horizontal tech logo. To the LEFT of the wordmark, an icon that fuses a "
                . "'layout' grid (a few rounded rectangular blocks arranged like a dashboard / ad layout) with an AI "
                . "spark (a small four-point sparkle accent). Icon uses the same blue-to-violet gradient. {$brand}",
            'v2-dot-ai-node' => "A minimalist horizontal tech logo where the period/dot in 'layout.ai' is replaced by a "
                . "glowing AI node — a small circle with a few thin connection lines radiating like a neural node. {$brand}",
            'v3-bracket-spark' => "A minimalist horizontal tech logo. The icon to the left is a rounded square containing "
                . "an abstract layout of stacked bars with a four-point AI sparkle overlapping the corner. Clean, techy. {$brand}",
            'v4-monogram' => "A minimalist horizontal tech logo with a left icon that is a geometric 'L' formed from "
                . "layout blocks, with a subtle AI spark dot. Balanced, modern, software brand. {$brand}",
        ];

        if ($this->option('prompt')) {
            $prompts = ['custom' => (string) $this->option('prompt') . ' ' . $brand];
        } elseif ($this->option('only')) {
            $k = $this->option('only');
            $match = collect(array_keys($prompts))->first(fn ($x) => str_starts_with($x, $k));
            if ($match) {
                $prompts = [$match => $prompts[$match]];
            }
        }

        $dir = storage_path('app/logo-gen');
        @mkdir($dir, 0775, true);

        foreach ($prompts as $name => $prompt) {
            $this->line("→ {$name}");
            try {
                $res = Http::timeout(120)->post($url, [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']],
                ]);
            } catch (\Throwable $e) {
                $this->error("  exception: " . $e->getMessage());
                continue;
            }
            if (! $res->successful()) {
                $this->error("  HTTP {$res->status()}: " . substr($res->body(), 0, 300));
                continue;
            }
            $parts = $res->json('candidates.0.content.parts') ?? [];
            $saved = false;
            foreach ($parts as $part) {
                $data = $part['inlineData']['data'] ?? $part['inline_data']['data'] ?? null;
                if ($data) {
                    file_put_contents("{$dir}/{$name}.png", base64_decode($data));
                    $this->info("  saved {$name}.png");
                    $saved = true;
                    break;
                }
            }
            if (! $saved) {
                $this->warn("  no image part. keys=" . json_encode(array_map(fn ($p) => array_keys($p), $parts)));
            }
        }

        $this->info("Artifacts: {$dir}");
        return self::SUCCESS;
    }

    /** Trim near-white/transparent margins off a generated logo and install it. */
    private function install(string $variant): int
    {
        $src = storage_path("app/logo-gen/{$variant}.png");
        if (! is_file($src)) {
            // allow prefix match (e.g. "v1")
            $match = collect(glob(storage_path('app/logo-gen/*.png')))
                ->first(fn ($p) => str_starts_with(basename($p, '.png'), $variant));
            if (! $match) {
                $this->error("no generated logo matching '{$variant}'");
                return self::FAILURE;
            }
            $src = $match;
        }

        $img = @imagecreatefrompng($src);
        if (! $img) {
            $this->error('could not read ' . $src);
            return self::FAILURE;
        }
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $w = imagesx($img);
        $h = imagesy($img);

        // Bounding box of non-white, non-transparent pixels.
        $minX = $w; $minY = $h; $maxX = -1; $maxY = -1;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($img, $x, $y);
                $a = ($rgba >> 24) & 0x7F;
                if ($a > 100) continue;                       // transparent
                $r = ($rgba >> 16) & 0xFF; $g = ($rgba >> 8) & 0xFF; $b = $rgba & 0xFF;
                if ($r > 244 && $g > 244 && $b > 244) continue; // near-white
                if ($x < $minX) $minX = $x; if ($x > $maxX) $maxX = $x;
                if ($y < $minY) $minY = $y; if ($y > $maxY) $maxY = $y;
            }
        }
        if ($maxX < 0) {
            $this->error('image looks blank after trim scan');
            return self::FAILURE;
        }

        // Pad ~4% of the content height around the box.
        $pad = max(8, (int) round(($maxY - $minY) * 0.10));
        $minX = max(0, $minX - $pad); $minY = max(0, $minY - $pad);
        $maxX = min($w - 1, $maxX + $pad); $maxY = min($h - 1, $maxY + $pad);
        $cw = $maxX - $minX + 1; $ch = $maxY - $minY + 1;

        $out = imagecreatetruecolor($cw, $ch);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopy($out, $img, 0, 0, $minX, $minY, $cw, $ch);

        $dest = public_path('img/logo.png');
        if (is_file($dest)) {
            copy($dest, public_path('img/logo-prev.png'));
        }
        imagepng($out, $dest);
        imagedestroy($img); imagedestroy($out);

        $this->info("installed {$variant} → public/img/logo.png  ({$cw}x{$ch}, was {$w}x{$h}); backup at img/logo-prev.png");
        return self::SUCCESS;
    }
}
