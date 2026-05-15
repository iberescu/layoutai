<?php

namespace App\Services;

use App\Models\AdImage;
use App\Models\AdVariant;
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

        $existing = AdImage::where('prompt_hash', $hash)->whereNotNull('stored_url')->first();
        if ($existing) {
            $image = $variant->image()->create([
                'prompt'          => $clean,
                'prompt_hash'     => $hash,
                'source_url'      => $existing->source_url,
                'stored_url'      => $existing->stored_url,
                'status'          => 'reused',
                'width'           => $existing->width,
                'height'          => $existing->height,
                'file_size_bytes' => $existing->file_size_bytes,
            ]);
            return $image;
        }

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
