<?php

namespace App\Services;

use App\Models\AdRender;
use App\Models\AdVariant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdRenderService
{
    public function render(AdVariant $variant, string $format = 'png'): ?AdRender
    {
        if (! $variant->html) {
            return null;
        }
        $endpoint = rtrim((string) config('services.renderer.url'), '/');
        $url = $endpoint . '/render';

        try {
            // Renderer runs inside the docker network and cannot reach the
            // browser-facing APP_URL. Rewrite localhost references to the
            // internal nginx hostname so background images load.
            $html = $variant->html;
            $internal = (string) config('services.renderer.internal_url', 'http://nginx');
            $appUrl   = (string) config('app.url');
            if ($appUrl && $internal) {
                $html = str_replace($appUrl, $internal, $html);
            }

            $response = Http::timeout(60)->post($url, [
                'html'   => $html,
                'width'  => $variant->size_width,
                'height' => $variant->size_height,
                'format' => $format,
            ]);
            if (! $response->successful()) {
                throw new \RuntimeException('Renderer returned ' . $response->status());
            }
            $bytes = $response->body();
            $ext   = $format === 'jpg' ? 'jpg' : 'png';
            $path  = 'generated/ad-renders/' . Str::uuid() . '.' . $ext;
            Storage::disk(config('filesystems.default', 'public'))->put($path, $bytes);
            $assetUrl = Storage::disk(config('filesystems.default', 'public'))->url($path);

            return AdRender::create([
                'ad_variant_id'   => $variant->id,
                'format'          => $ext,
                'asset_url'       => $assetUrl,
                'file_size_bytes' => strlen($bytes),
                'width'           => $variant->size_width,
                'height'          => $variant->size_height,
                'render_status'   => 'completed',
                'created_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Ad render failed: ' . $e->getMessage());
            return AdRender::create([
                'ad_variant_id'   => $variant->id,
                'format'          => $format,
                'asset_url'       => null,
                'file_size_bytes' => 0,
                'width'           => $variant->size_width,
                'height'          => $variant->size_height,
                'render_status'   => 'failed',
                'validation_errors_json' => [$e->getMessage()],
                'created_at'      => now(),
            ]);
        }
    }
}
