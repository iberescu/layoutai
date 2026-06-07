<?php

namespace App\Services\Events;

use App\Models\NewsEventHook;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pulls every event source once per day and upserts results into the
 * news_event_hooks table. Sources are queried independently — one failing
 * doesn't take the rest down. Idempotent via (source, external_id) unique
 * key, so reruns and retries are safe.
 */
class DailyEventsRefresher
{
    public function __construct(
        private readonly WeatherEventSource  $weather,
        private readonly MarketEventSource   $market,
        private readonly TechNewsEventSource $tech,
        private readonly HolidayEventSource  $holiday,
    ) {}

    /**
     * @return array{written:int, by_source:array<string,int>}
     */
    public function refresh(): array
    {
        $sources = [
            'weather' => $this->weather,
            'market'  => $this->market,
            'tech'    => $this->tech,
            'holiday' => $this->holiday,
        ];

        $bySource = [];
        $total    = 0;

        foreach ($sources as $name => $svc) {
            $count = 0;
            try {
                $events = $svc->fetch();
                foreach ($events as $e) {
                    NewsEventHook::updateOrCreate(
                        ['source' => $e['source'], 'external_id' => $e['external_id']],
                        [
                            'title'             => $e['title'],
                            'type'              => $e['type'],
                            'location'          => $e['location']        ?? null,
                            'date'              => $e['date']            ?? null,
                            'expires_at'        => $e['expires_at']      ?? null,
                            'relevance_score'   => $e['relevance_score'] ?? 0.5,
                            'risk_score'        => $e['risk_score']      ?? 0.1,
                            'recommended_angle' => $e['recommended_angle'] ?? null,
                            'avoid'             => $e['avoid']           ?? [],
                            'meta'              => $e['meta']            ?? [],
                        ],
                    );
                    $count++;
                }
            } catch (\Throwable $ex) {
                Log::warning("DailyEventsRefresher source {$name} failed: ".$ex->getMessage());
            }
            $bySource[$name] = $count;
            $total += $count;
        }

        // Tidy: drop hooks that have been expired for >30 days so the table
        // doesn't grow unbounded across years.
        NewsEventHook::whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::now()->subDays(30))
            ->delete();

        return ['written' => $total, 'by_source' => $bySource];
    }
}
