<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Asks the renderer container's /focal endpoint to run smartcrop on an
 * image and return the focal-point coordinates as percentages (0-100).
 *
 * The HTML pipeline then applies these as `object-position: X% Y%` so that
 * when an ad's iframe crops the image via `object-fit: cover`, the subject
 * of interest (face, product, focal edge) stays in frame across every ad
 * size. Without this, browsers default to `50% 50%` and the subject often
 * gets clipped on narrow leaderboards or tall skyscrapers.
 */
class ImageFocalService
{
    /** @return array{x: float, y: float}|null */
    public function focalFor(string $imageUrl): ?array
    {
        $base = rtrim((string) config('services.renderer.url', 'http://renderer:3000'), '/');

        try {
            $r = Http::timeout(15)
                ->retry(1, 600)
                ->acceptJson()
                ->post("{$base}/focal", ['url' => $imageUrl]);
        } catch (\Throwable $e) {
            Log::info('ImageFocalService: '.$e->getMessage());
            return null;
        }
        if (! $r->successful()) {
            Log::info('ImageFocalService non-2xx: '.$r->status());
            return null;
        }
        $body = $r->json();
        if (! is_array($body)) return null;

        $x = isset($body['focal_x']) ? (float) $body['focal_x'] : null;
        $y = isset($body['focal_y']) ? (float) $body['focal_y'] : null;
        if ($x === null || $y === null) return null;

        return ['x' => max(0.0, min(100.0, $x)), 'y' => max(0.0, min(100.0, $y))];
    }
}
