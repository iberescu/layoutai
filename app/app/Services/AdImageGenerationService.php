<?php

namespace App\Services;

use App\Models\AdImage;
use App\Models\AdVariant;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdImageGenerationService
{
    public function generateForVariant(AdVariant $variant, string $prompt): AdImage
    {
        $clean = $this->cleanPrompt($prompt);
        $hash  = hash('sha256', $clean);

        // Cheap path: another worker already produced an AdImage for this
        // prompt — reuse the stored bytes + crop hints.
        $reused = $this->reuseExisting($variant, $clean, $hash);
        if ($reused) return $reused;

        // Per-prompt lock: when ~24 AI-image variants collapse onto 10 distinct
        // prompts, multiple workers will race to fetch the same prompt. The
        // lock serialises them — first worker fetches, the rest block here
        // and then take the cache-hit path below once it lands.
        $lock = Cache::lock('ad-image-fetch:' . $hash, 120);
        try {
            $lock->block(100); // wait up to 100s for the holder to finish
        } catch (LockTimeoutException) {
            // Couldn't acquire — fall through and fetch ourselves rather than
            // fail the variant. Duplicate fetches are wasteful but recoverable.
            Log::info("AdImage lock timeout for {$hash}; fetching anyway");
        }

        try {
            // Re-check after the lock — the holder likely just finished.
            $reused = $this->reuseExisting($variant, $clean, $hash);
            if ($reused) return $reused;

            return $this->fetchAndStore($variant, $clean, $hash);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Look up an existing AdImage by prompt_hash and clone it onto $variant.
     * Returns null if no usable cache row exists.
     */
    private function reuseExisting(AdVariant $variant, string $clean, string $hash): ?AdImage
    {
        $existing = AdImage::where('prompt_hash', $hash)->whereNotNull('stored_url')->first();
        if (! $existing || ! $this->storedFileExists($existing->stored_url)) {
            if ($existing) {
                Log::info("AdImage cache hit but file missing — re-fetching for hash {$hash}");
            }
            return null;
        }
        return $variant->image()->create([
            'prompt'          => $clean,
            'prompt_hash'     => $hash,
            'source_url'      => $existing->source_url,
            'stored_url'      => $existing->stored_url,
            'focal_x'         => $existing->focal_x,
            'focal_y'         => $existing->focal_y,
            'status'          => 'reused',
            'width'           => $existing->width,
            'height'          => $existing->height,
            'file_size_bytes' => $existing->file_size_bytes,
        ]);
    }

    private function fetchAndStore(AdVariant $variant, string $clean, string $hash): AdImage
    {
        $endpoint = (string) config('services.runmyprint.endpoint');
        $url = $endpoint . '?' . http_build_query(['prompt' => $clean]);

        try {
            $response = Http::timeout(90)->retry(2, 1500)->get($url);
            if (! $response->successful()) {
                throw new \RuntimeException('Image endpoint status ' . $response->status());
            }
            $contentType = (string) $response->header('Content-Type');
            if (! str_contains($contentType, 'image/')) {
                throw new \RuntimeException('Image endpoint did not return an image');
            }
            $ext  = str_contains($contentType, 'png') ? 'png' : 'jpg';
            $path = 'generated/ad-images/' . Str::uuid() . '.' . $ext;
            Storage::disk(config('filesystems.default', 'public'))->put($path, $response->body());
            $stored = Storage::disk(config('filesystems.default', 'public'))->url($path);

            // Smartcrop focal computation is NOT applied to runmyprint AI
            // images — they're already centrally composed (the model frames
            // its subject around the canvas centre). Focal is reserved for
            // harvested brand-website photos where composition varies.
            return $variant->image()->create([
                'prompt'          => $clean,
                'prompt_hash'     => $hash,
                'source_url'      => $url,
                'stored_url'      => $stored,
                'status'          => 'completed',
                'file_size_bytes' => strlen((string) $response->body()),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Image generation failed: ' . $e->getMessage());
            // Fallback: brand-color SVG placeholder so the pipeline keeps moving.
            $svg = $this->fallbackSvg($variant);
            $path = 'generated/ad-images/fallback-' . Str::uuid() . '.svg';
            Storage::disk(config('filesystems.default', 'public'))->put($path, $svg);
            $stored = Storage::disk(config('filesystems.default', 'public'))->url($path);

            return $variant->image()->create([
                'prompt'          => $clean,
                'prompt_hash'     => $hash,
                'source_url'      => $url,
                'stored_url'      => $stored,
                'status'          => 'needs_regeneration',
                'error_message'   => $e->getMessage(),
                'file_size_bytes' => strlen($svg),
            ]);
        }
    }

    public function cleanPrompt(string $prompt): string
    {
        $prompt = strip_tags($prompt);
        $prompt = (string) preg_replace('/\s+/', ' ', $prompt);
        return Str::limit(trim($prompt), 700, '');
    }

    /**
     * Map a stored URL back to the disk path and confirm the bytes are
     * really there. Needed because the worker containers' storage volume
     * can be recreated between deploys, leaving AdImage cache rows that
     * point at ghosts.
     */
    private function storedFileExists(string $storedUrl): bool
    {
        $publicBase = '/storage/';
        $pos = strpos($storedUrl, $publicBase);
        if ($pos === false) return false;
        $relative = substr($storedUrl, $pos + strlen($publicBase));
        return Storage::disk(config('filesystems.default', 'public'))->exists($relative);
    }

    private function fallbackSvg(AdVariant $variant): string
    {
        $primary = $variant->meta['primary_color'] ?? '#2563EB';
        $accent  = $variant->meta['accent_color']  ?? '#7C3AED';
        $w = $variant->size_width;
        $h = $variant->size_height;
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$w}" height="{$h}" viewBox="0 0 {$w} {$h}">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="{$primary}"/>
      <stop offset="100%" stop-color="{$accent}"/>
    </linearGradient>
  </defs>
  <rect width="{$w}" height="{$h}" fill="url(#g)"/>
</svg>
SVG;
    }
}
