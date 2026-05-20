<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiClient
{
    public function isConfigured(): bool
    {
        return ! empty(config('services.gemini.api_key'));
    }

    public function generateJson(string $prompt, array $schema = [], ?string $modelOverride = null): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }
        $base    = rtrim((string) config('services.gemini.base'), '/');
        $model   = $modelOverride !== null && $modelOverride !== ''
            ? $modelOverride
            : (string) config('services.gemini.model');
        $key     = (string) config('services.gemini.api_key');
        $url     = "{$base}/models/{$model}:generateContent?key={$key}";

        $body = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => array_filter([
                'responseMimeType' => 'application/json',
                'responseSchema'   => $schema ?: null,
                // Disable Flash's "thinking" pre-pass — saves ~50% latency on
                // templated/structured output where extra reasoning doesn't help.
                'thinkingConfig'   => ['thinkingBudget' => 0],
            ]),
        ];

        try {
            $res = $this->http()->post($url, $body);
            if (! $res->successful()) {
                Log::warning('Gemini call failed: ' . $res->status() . ' ' . $res->body());
                return null;
            }
            $data = $res->json();
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (! $text) {
                return null;
            }
            $decoded = json_decode($text, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            Log::warning('Gemini exception: ' . $e->getMessage());
            return null;
        }
    }

    protected function http(): PendingRequest
    {
        // 180s headroom: the combined brand+ads call generates 30 concepts in
        // one request and routinely runs 30-60s on gemini-3.5-flash. Smaller
        // calls (brand-only, HTML per-variant) finish in <15s regardless.
        return Http::timeout(180)->retry(2, 1000);
    }
}
