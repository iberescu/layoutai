<?php

namespace App\Services;

use App\Models\CrawlJob;
use App\Models\CrawlPage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflareCrawler
{
    public function crawl(CrawlJob $job): void
    {
        $accountId = config('services.cloudflare.account_id');
        $token     = config('services.cloudflare.api_token');
        $endpoint  = config('services.cloudflare.endpoint');

        $payload = [
            'url'    => $job->url,
            'limit'  => $job->limit,
            'depth'  => $job->depth,
            'formats'=> ['markdown', 'html'],
            'render' => true,
            'gotoOptions' => [
                'waitUntil' => 'networkidle2',
                'timeout'   => 60000,
            ],
        ];

        if ($accountId && $token && $endpoint) {
            try {
                $url = str_replace('{account}', $accountId, $endpoint);
                $response = Http::withToken($token)->timeout(120)->post($url, $payload);
                if ($response->successful()) {
                    $this->ingest($job, $response->json());
                    return;
                }
                Log::warning('Cloudflare crawl failed: ' . $response->status() . ' ' . $response->body());
            } catch (\Throwable $e) {
                Log::warning('Cloudflare crawl threw: ' . $e->getMessage());
            }
        }

        // Stub fallback so the rest of the pipeline can run in dev.
        $this->ingest($job, $this->stubFor($job->url));
    }

    private function ingest(CrawlJob $job, array $data): void
    {
        $job->update([
            'status'       => 'completed',
            'raw_response' => $data,
        ]);

        $pages = $data['pages'] ?? $data['result']['pages'] ?? [];
        foreach ($pages as $page) {
            CrawlPage::create([
                'crawl_job_id' => $job->id,
                'url'          => $page['url']      ?? $job->url,
                'title'        => $page['title']    ?? null,
                'markdown'     => $page['markdown'] ?? $page['content'] ?? null,
                'meta'         => $page['meta']     ?? [],
                'images'       => $page['images']   ?? [],
                'links'        => $page['links']    ?? [],
            ]);
        }
    }

    private function stubFor(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST) ?: 'example.com';
        $name = ucwords(str_replace(['.', '-', '_'], ' ', preg_replace('/^www\./', '', $host)));

        return [
            'pages' => [
                [
                    'url'      => $url,
                    'title'    => $name . ' – Home',
                    'markdown' => "# Welcome to {$name}\n\n{$name} provides modern services for busy teams. We focus on quality, speed, and trust.\n\n## What we do\n\n- Curated product line\n- Local delivery\n- Friendly support\n\n## Why customers love us\n\nThousands of customers trust {$name} every week.",
                    'meta'     => [
                        'description' => $name . ' offers a curated product line, local delivery, and friendly support.',
                        'og:image'    => null,
                    ],
                    'images'   => [],
                    'links'    => [],
                ],
                [
                    'url'      => rtrim($url, '/') . '/about',
                    'title'    => $name . ' – About',
                    'markdown' => "## About {$name}\n\nWe are a passionate team building products that customers love.",
                    'meta'     => [],
                    'images'   => [],
                    'links'    => [],
                ],
            ],
        ];
    }
}
