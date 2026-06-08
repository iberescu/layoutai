<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Purges the Cloudflare CDN cache so changed static assets (logos, CSS, JS) go
 * live instantly instead of waiting out the ~4h edge TTL. Uses the zone-scoped
 * CLOUDFLARE_DNS_TOKEN. Run with --all (deploy) or pass specific file URLs.
 *
 *   php artisan cloudflare:purge --all
 *   php artisan cloudflare:purge https://layout.ai/img/logo.png
 */
class CloudflarePurgeCommand extends Command
{
    protected $signature = 'cloudflare:purge {urls?* : Specific file URLs to purge} {--all : Purge the entire zone}';
    protected $description = 'Purge the Cloudflare CDN cache (whole zone or specific URLs)';

    public function handle(): int
    {
        $token = (string) config('services.cloudflare.dns_token');
        $zone  = (string) config('services.cloudflare.zone_id');
        if ($token === '' || $zone === '') {
            $this->warn('CLOUDFLARE_DNS_TOKEN / CLOUDFLARE_ZONE_ID not set — skipping purge.');
            return self::SUCCESS; // don't fail a deploy over a missing optional token
        }

        $urls = (array) $this->argument('urls');
        if ($urls) {
            $payload = ['files' => array_values($urls)];
            $what = count($urls) . ' file(s)';
        } elseif ($this->option('all')) {
            $payload = ['purge_everything' => true];
            $what = 'entire zone';
        } else {
            $this->error('Pass file URLs or --all.');
            return self::FAILURE;
        }

        try {
            $res = Http::withToken($token)->timeout(20)
                ->post("https://api.cloudflare.com/client/v4/zones/{$zone}/purge_cache", $payload);
            if ($res->successful() && $res->json('success')) {
                $this->info("Cloudflare cache purged ({$what}).");
                return self::SUCCESS;
            }
            $this->warn('Cloudflare purge failed: ' . substr($res->body(), 0, 200));
        } catch (\Throwable $e) {
            $this->warn('Cloudflare purge error: ' . $e->getMessage());
        }
        return self::SUCCESS; // never block a deploy
    }
}
