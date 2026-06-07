<?php

namespace App\Console\Commands;

use App\Services\Events\DailyEventsRefresher;
use Illuminate\Console\Command;

class RefreshDailyEventsCommand extends Command
{
    protected $signature   = 'layout:refresh-daily-events';
    protected $description = 'Pull weather, market, tech-news, and holiday events into news_event_hooks. Runs once a day so ad generation hits a hot cache instead of external APIs.';

    public function handle(DailyEventsRefresher $refresher): int
    {
        $result = $refresher->refresh();
        $this->info("Wrote {$result['written']} event(s).");
        foreach ($result['by_source'] as $source => $n) {
            $this->line("  - {$source}: {$n}");
        }
        return self::SUCCESS;
    }
}
