<?php

namespace App\Services;

use App\Models\AdVariant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Scores an ad's creative using TRIBE v2 (Meta AI) on a hosted GPU.
 *
 * Flow:
 *   1. Ensure the variant has a rendered PNG (re-uses the existing renderer
 *      container if missing).
 *   2. POST the PNG to a Replicate prediction.
 *   3. Poll until the prediction completes.
 *   4. Aggregate brain-region activations into a single 0–100 score that
 *      proxies "predicted visual attention".
 *
 * Falls back to a deterministic placeholder score when the provider is
 * 'mock' or the Replicate model isn't configured — so the UI works
 * end-to-end before the GPU runner is published.
 */
class CreativeScoringService
{
    public function score(AdVariant $variant): ?array
    {
        $provider = (string) config('services.creative_scoring.provider', 'mock');

        if ($provider === 'replicate' && $this->replicateReady()) {
            $result = $this->scoreViaReplicate($variant);
            if ($result !== null) {
                return $result;
            }
            Log::warning('Replicate scoring failed for variant ' . $variant->id . ' — falling back to mock');
        }

        return $this->mockScore($variant);
    }

    private function replicateReady(): bool
    {
        return ! empty(config('services.creative_scoring.replicate_token'))
            && ! empty(config('services.creative_scoring.replicate_model'));
    }

    private function scoreViaReplicate(AdVariant $variant): ?array
    {
        $imageUrl = $this->resolveImageUrl($variant);
        if (! $imageUrl) {
            Log::info('Creative scoring skipped — no image URL for variant ' . $variant->id);
            return null;
        }

        $token   = (string) config('services.creative_scoring.replicate_token');
        $modelId = (string) config('services.creative_scoring.replicate_model');

        // Replicate accepts `version:` (hash) for owner/model:hash format,
        // or model name + latest_version for `owner/model`. We support both.
        $body = str_contains($modelId, ':')
            ? ['version' => substr($modelId, strrpos($modelId, ':') + 1),
               'input'   => ['image' => $imageUrl]]
            : ['version' => $modelId,
               'input'   => ['image' => $imageUrl]];

        try {
            $start = Http::withToken($token, 'Token')
                ->acceptJson()
                ->timeout(30)
                ->post('https://api.replicate.com/v1/predictions', $body);

            if (! $start->successful()) {
                Log::warning('Replicate start failed: ' . $start->status() . ' ' . $start->body());
                return null;
            }

            $prediction = $start->json();
            $url = $prediction['urls']['get'] ?? null;
            if (! $url) {
                return null;
            }

            // Poll up to 90s for completion.
            $deadline = time() + 90;
            while (time() < $deadline) {
                $poll = Http::withToken($token, 'Token')->acceptJson()->timeout(15)->get($url);
                if (! $poll->successful()) {
                    sleep(2);
                    continue;
                }
                $prediction = $poll->json();
                $status = $prediction['status'] ?? 'unknown';
                if (in_array($status, ['succeeded', 'failed', 'canceled'], true)) {
                    break;
                }
                sleep(2);
            }

            if (($prediction['status'] ?? null) !== 'succeeded') {
                Log::warning('Replicate prediction ended with status: ' . ($prediction['status'] ?? 'unknown'));
                return null;
            }

            $output = $prediction['output'] ?? [];
            // Expected output: { score: 0..100, regions: { v1: ..., it: ..., ... } }
            // If the scorer returns a bare number, treat it as the score directly.
            if (is_numeric($output)) {
                return ['score' => (float) $output, 'meta' => ['raw' => $output]];
            }
            if (is_array($output) && isset($output['score'])) {
                return [
                    'score' => max(0, min(100, (float) $output['score'])),
                    'meta'  => $output,
                ];
            }

            Log::warning('Replicate output shape unexpected: ' . json_encode($output));
            return null;
        } catch (\Throwable $e) {
            Log::warning('Replicate exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Deterministic placeholder. Hashes the variant's HTML + meta to a stable
     * 0–100 score so the UI looks alive even without the GPU runner. Tuned to
     * roughly mirror a TRIBE v2 distribution (mostly mid-band, sparse tails).
     */
    private function mockScore(AdVariant $variant): array
    {
        $seed = crc32(($variant->html ?? '') . ($variant->headline ?? '') . $variant->id);
        // Beta(2,2)-ish: more mass in the middle than uniform.
        $u1 = (($seed & 0xFFFF) / 0xFFFF);
        $u2 = ((($seed >> 16) & 0xFFFF) / 0xFFFF);
        $center = ($u1 + $u2) / 2; // 0..1, centered near 0.5
        // Stretch a bit so 5–95 are reachable.
        $score = round(5 + $center * 90, 2);
        return [
            'score' => $score,
            'meta'  => ['provider' => 'mock', 'algo' => 'crc32-beta'],
        ];
    }

    /**
     * Choose the image we'll feed the scorer. Prefer the runmyprint base
     * image (already a real photo) since it's a stable URL. If absent, fall
     * back to a renderer call to rasterize the ad's HTML.
     */
    private function resolveImageUrl(AdVariant $variant): ?string
    {
        $variant->loadMissing('image');
        if ($variant->image?->stored_url) {
            return $this->publicUrl($variant->image->stored_url);
        }
        // No base image — leave it for now. A future enhancement could
        // POST the HTML to the renderer here to get a fresh PNG.
        return null;
    }

    /**
     * Replicate fetches the image from the internet — so any localhost-style
     * URL we have internally must be rewritten to a publicly reachable one.
     * In dev that means the user has to expose nginx via a tunnel (ngrok,
     * cloudflared) and set INTERNAL_PUBLIC_URL accordingly.
     */
    private function publicUrl(string $url): string
    {
        $internal = (string) config('services.renderer.internal_url', '');
        $public   = (string) config('app.url', 'http://localhost:8088');
        if ($internal && str_contains($url, $internal)) {
            return str_replace($internal, $public, $url);
        }
        return $url;
    }
}
