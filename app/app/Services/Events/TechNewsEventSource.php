<?php

namespace App\Services\Events;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Hacker News top stories (free, no auth). Surfaces the tech zeitgeist —
 * brands in adjacent verticals can lean on what their target audience is
 * talking about this week. Heavily filtered: > 200 points, no "ask",
 * "show", or job posts, no obvious controversy keywords.
 */
class TechNewsEventSource
{
    private const TAKE      = 5;       // how many top stories to consider
    private const MIN_SCORE = 200;
    private const BAD_TERMS = [
        // Skip topics that are either too inflammatory or tend to invite ad-policy risk.
        'fired','lawsuit','scandal','dies','died','death','died at','breach',
        'leaked','protest','war','strike','israel','palestine','russia','ukraine',
        'shooter','shooting','suicide','crash','outage',
    ];

    public function fetch(): array
    {
        try {
            $top = Http::timeout(15)->retry(2, 800)
                ->get('https://hacker-news.firebaseio.com/v0/topstories.json')
                ->json();
        } catch (\Throwable $e) {
            Log::info('TechNewsEventSource topstories failed: '.$e->getMessage());
            return [];
        }
        if (! is_array($top)) return [];

        $out = [];
        $picked = 0;
        foreach ($top as $id) {
            if ($picked >= self::TAKE) break;
            try {
                $item = Http::timeout(10)->retry(1, 500)
                    ->get("https://hacker-news.firebaseio.com/v0/item/{$id}.json")
                    ->json();
            } catch (\Throwable $e) {
                continue;
            }
            if (! is_array($item)) continue;
            if (($item['type'] ?? '') !== 'story') continue;
            if (! empty($item['deleted']) || ! empty($item['dead'])) continue;
            if ((int) ($item['score'] ?? 0) < self::MIN_SCORE) continue;

            $title = (string) ($item['title'] ?? '');
            if ($title === '') continue;
            $lower = strtolower($title);
            if (str_starts_with($lower, 'ask hn') || str_starts_with($lower, 'show hn')) continue;
            foreach (self::BAD_TERMS as $t) {
                if (str_contains($lower, $t)) continue 2;
            }

            $out[] = [
                'source'           => 'tech',
                'external_id'      => 'hn:'.$id,
                'title'            => str($title)->limit(140)->toString(),
                'type'             => 'tech_news',
                'location'         => null,
                'date'             => Carbon::now()->toDateString(),
                'expires_at'       => Carbon::now()->addDays(4),
                'relevance_score'  => min(0.85, ((int) $item['score']) / 1000.0 + 0.45),
                'risk_score'       => 0.10,
                'recommended_angle'=> "Tech-forward angle riffing on: \"{$title}\". Tie it to the brand only if it lands naturally.",
                'avoid'            => ['making the brand look opportunistic', 'taking sides on industry debates'],
                'meta'             => ['hn_score' => $item['score'] ?? null, 'url' => $item['url'] ?? null],
            ];
            $picked++;
        }
        return $out;
    }
}
