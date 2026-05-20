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
        if (empty(config('services.creative_scoring.replicate_token'))) {
            return false;
        }
        return ! empty(config('services.creative_scoring.replicate_deployment'))
            || ! empty(config('services.creative_scoring.replicate_model'));
    }

    private function scoreViaReplicate(AdVariant $variant): ?array
    {
        $adText = $this->resolveAdText($variant);
        if ($adText === null) {
            Log::info('Creative scoring skipped — no ad text for variant ' . $variant->id);
            return null;
        }

        $token      = (string) config('services.creative_scoring.replicate_token');
        $deployment = (string) config('services.creative_scoring.replicate_deployment');
        $modelId    = (string) config('services.creative_scoring.replicate_model');

        // Deployments are preferred: they give us explicit max_instances
        // control so 30 ads from one campaign can fan out across replicas.
        // Falls back to the model-prediction endpoint when no deployment.
        if ($deployment !== '') {
            $endpoint = "https://api.replicate.com/v1/deployments/{$deployment}/predictions";
            $body     = ['input' => ['text' => $adText]];
        } else {
            $endpoint = 'https://api.replicate.com/v1/predictions';
            $body     = str_contains($modelId, ':')
                ? ['version' => substr($modelId, strrpos($modelId, ':') + 1),
                   'input'   => ['text' => $adText]]
                : ['version' => $modelId,
                   'input'   => ['text' => $adText]];
        }

        try {
            $start = Http::withToken($token, 'Token')
                ->acceptJson()
                ->timeout(30)
                ->post($endpoint, $body);

            if (! $start->successful()) {
                Log::warning('Replicate start failed: ' . $start->status() . ' ' . $start->body());
                return null;
            }

            $prediction = $start->json();
            $url = $prediction['urls']['get'] ?? null;
            if (! $url) {
                return null;
            }

            // Poll up to 10 min: TRIBE v2 takes ~75s when warm, but the
            // first call after a replica cold-start can take 4-5 min while
            // Replicate pulls the 24 GB image.
            $deadline = time() + 600;
            while (time() < $deadline) {
                $poll = Http::withToken($token, 'Token')->acceptJson()->timeout(15)->get($url);
                if (! $poll->successful()) {
                    sleep(3);
                    continue;
                }
                $prediction = $poll->json();
                $status = $prediction['status'] ?? 'unknown';
                if (in_array($status, ['succeeded', 'failed', 'canceled'], true)) {
                    break;
                }
                sleep(5);
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
     * Compose the ad's copy that gets fed to TRIBE v2's text pathway:
     * headline + subheadline + CTA, joined as a short natural-language
     * paragraph. TRIBE v2 internally converts this to speech (gTTS) and
     * extracts word-level timings before running the language pathway,
     * so punctuation matters for prosody.
     */
    private function resolveAdText(AdVariant $variant): ?string
    {
        $parts = array_filter([
            $this->endWithPeriod($variant->headline),
            $this->endWithPeriod($variant->subheadline),
            $this->endWithPeriod($variant->cta),
        ], fn ($p) => $p !== '' && $p !== null);

        if (empty($parts)) {
            return null;
        }
        return implode(' ', $parts);
    }

    private function endWithPeriod(?string $s): ?string
    {
        $s = trim((string) $s);
        if ($s === '') return null;
        // Add a trailing period so gTTS gives each segment proper prosody.
        return preg_match('/[.!?]$/', $s) ? $s : $s . '.';
    }
}
