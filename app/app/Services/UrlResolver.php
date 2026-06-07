<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Resolves what the user typed into a canonical, reachable URL before we
 * hand it off to the (slow) Cloudflare browser-rendering crawler. Probes
 * variants in priority order with a short cURL HEAD-then-GET:
 *
 *   1. The URL as given (after scheme normalisation)
 *   2. The same host with the opposite www/no-www flip
 *   3. Both variants over http:// as last-resort fallback
 *
 * Returns the first variant that produces a 2xx or follows redirects to one.
 * If none respond, returns the original URL so the crawl job surfaces its
 * own error with our regular "couldn't read enough content" failure path.
 *
 * Total budget: ~10s in the worst case (4 candidates × 2.5s), but usually
 * exits after the first probe.
 */
class UrlResolver
{
    private const PROBE_TIMEOUT_SECONDS = 5;
    private const USER_AGENT = 'Mozilla/5.0 (compatible; LayoutAIBot/1.0; +https://layout.ai/bot)';

    public function resolve(string $url): string
    {
        $url = $this->ensureScheme($url);
        $parts = parse_url($url);
        if (! $parts || empty($parts['host'])) {
            return $url; // malformed — leave as-is, downstream will fail clean
        }

        $candidates = $this->candidates($parts);

        foreach ($candidates as $candidate) {
            $final = $this->respondsAt($candidate);
            if ($final !== null) {
                return $final;
            }
        }

        // Nothing responded — keep the original (the empty-crawl guard will
        // surface a clean error to the user).
        return $url;
    }

    /**
     * Build the candidate list in priority order:
     *   1. as-given
     *   2. flipped www
     *   3. http:// as-given
     *   4. http:// flipped www
     */
    private function candidates(array $parts): array
    {
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'];
        $path   = $parts['path'] ?? '';

        $hasWww = str_starts_with(strtolower($host), 'www.');
        $flippedHost = $hasWww ? substr($host, 4) : 'www.' . $host;

        $build = fn ($s, $h) => $s . '://' . $h . rtrim($path, '/');

        return array_unique([
            $build($scheme, $host),
            $build($scheme, $flippedHost),
            $build('http',  $host),
            $build('http',  $flippedHost),
        ]);
    }

    /**
     * Short cURL probe. HEAD first; if the server doesn't support HEAD or
     * returns non-2xx for it, fall back to GET (which usually works for
     * SPAs and Cloudflare-fronted sites). Returns the final URL after any
     * redirects on success, null on failure.
     */
    private function respondsAt(string $url): ?string
    {
        $opts = ['allow_redirects' => ['max' => 5, 'track_redirects' => true]];

        try {
            $head = Http::timeout(self::PROBE_TIMEOUT_SECONDS)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->withOptions($opts)
                ->head($url);
            if ($head->successful()) {
                return $this->finalUrl($head, $url);
            }
        } catch (\Throwable) {
            // continue to GET
        }
        try {
            $get = Http::timeout(self::PROBE_TIMEOUT_SECONDS)
                ->withHeaders(['User-Agent' => self::USER_AGENT, 'Range' => 'bytes=0-1023'])
                ->withOptions($opts)
                ->get($url);
            if ($get->successful() || $get->status() === 206) {
                return $this->finalUrl($get, $url);
            }
        } catch (\Throwable) {
        }
        return null;
    }

    /**
     * Pull the last redirect URL from Guzzle's track_redirects header. Falls
     * back to the originally-probed URL when there were no redirects.
     */
    private function finalUrl($response, string $original): string
    {
        $chain = $response->header('X-Guzzle-Redirect-History');
        if ($chain) {
            $hops = preg_split('/\s*,\s*/', $chain);
            $last = end($hops);
            if (is_string($last) && $last !== '') {
                return rtrim($last, '/');
            }
        }
        return rtrim($original, '/');
    }

    private function ensureScheme(string $url): string
    {
        $url = trim($url);
        if ($url === '') return $url;
        if (! preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        return rtrim($url, '/');
    }
}
