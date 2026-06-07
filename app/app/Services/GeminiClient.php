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

    public function generateJson(string $prompt, array $schema = [], ?string $modelOverride = null, ?int $timeoutSeconds = null, ?int $maxOutputTokens = null): ?array
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
                // Hard ceiling on output. Default is too low for our 30-concept
                // brand+ads JSON which can run 60-80k chars; without this the
                // response gets cut mid-JSON and is unparseable.
                'maxOutputTokens'  => $maxOutputTokens,
            ], fn ($v) => $v !== null),
        ];

        try {
            $res = $this->http($timeoutSeconds)->post($url, $body);
            if (! $res->successful()) {
                $status = $res->status();
                $body   = $res->body();
                Log::warning("Gemini call failed: {$status} " . substr($body, 0, 300));
                // 429 with the spending-cap signature is a project-level billing
                // issue, not a per-request problem. Bubble it up so callers can
                // surface a clear "service quota exceeded" message instead of a
                // generic "AI failed" message.
                if ($status === 429 && (str_contains($body, 'spending cap') || str_contains($body, 'quota'))) {
                    throw new \App\Exceptions\GeminiQuotaExceededException('Gemini monthly spending cap reached');
                }
                return null;
            }
            $data = $res->json();
            $finish = $data['candidates'][0]['finishReason'] ?? null;
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (! $text) {
                Log::warning("Gemini empty text. finishReason={$finish}; promptFeedback=" . json_encode($data['promptFeedback'] ?? null));
                return null;
            }
            $decoded = json_decode($text, true);
            if (! is_array($decoded)) {
                Log::warning("Gemini text not parseable as JSON (finishReason={$finish}, len=" . strlen($text) . "). First 200: " . substr($text, 0, 200));
                return null;
            }
            return $decoded;
        } catch (\App\Exceptions\GeminiQuotaExceededException $e) {
            // Bubble up — distinct from a transient/format failure.
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Gemini exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Vision variant of generateJson: sends one or more inline images alongside
     * the prompt and forces a JSON response. Used by the template validation
     * judge (screenshot → rubric score) and brand-font inference (logo → fonts).
     *
     * @param array<int,array{bytes:string,mime:string}> $images
     */
    public function generateJsonWithImages(string $prompt, array $images, array $schema = [], ?string $modelOverride = null, ?int $timeoutSeconds = null, ?int $maxOutputTokens = null): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }
        $base  = rtrim((string) config('services.gemini.base'), '/');
        // Vision needs a multimodal model; default to the HTML model (2.5-flash
        // is multimodal) unless the caller overrides.
        $model = $modelOverride !== null && $modelOverride !== ''
            ? $modelOverride
            : (string) config('services.gemini.model');
        $key   = (string) config('services.gemini.api_key');
        $url   = "{$base}/models/{$model}:generateContent?key={$key}";

        $parts = [['text' => $prompt]];
        foreach ($images as $img) {
            if (empty($img['bytes'])) {
                continue;
            }
            $parts[] = ['inline_data' => [
                'mime_type' => $img['mime'] ?? 'image/png',
                'data'      => base64_encode($img['bytes']),
            ]];
        }

        $body = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => array_filter([
                'responseMimeType' => 'application/json',
                'responseSchema'   => $schema ?: null,
                'thinkingConfig'   => ['thinkingBudget' => 0],
                'maxOutputTokens'  => $maxOutputTokens,
            ], fn ($v) => $v !== null),
        ];

        try {
            $res = $this->http($timeoutSeconds)->post($url, $body);
            if (! $res->successful()) {
                Log::warning('Gemini vision call failed: ' . $res->status() . ' ' . substr($res->body(), 0, 300));
                return null;
            }
            $text = $res->json()['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (! $text) {
                return null;
            }
            $decoded = json_decode($text, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            Log::warning('Gemini vision exception: ' . $e->getMessage());
            return null;
        }
    }

    protected function http(?int $timeoutSeconds = null): PendingRequest
    {
        // Default 180s. The combined brand+ads call now generates 30 concepts
        // across 6 cohorts with style-specific directives — for some inputs
        // (especially political / non-English content where the safety
        // classifier is heavier) it can exceed 4 minutes. Callers that need
        // headroom pass an explicit timeout. Single retry (was 2) so a
        // genuine failure surfaces in 2× timeout, not 3×.
        return Http::timeout($timeoutSeconds ?? 180)->retry(1, 1500);
    }
}
