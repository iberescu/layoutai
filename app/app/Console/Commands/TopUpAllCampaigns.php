<?php

namespace App\Console\Commands;

use App\Jobs\TopUpCampaignAdsJob;
use App\Models\AdVariant;
use App\Models\Campaign;
use Illuminate\Console\Command;

class TopUpAllCampaigns extends Command
{
    protected $signature   = 'layout:top-up-campaigns {--target=100 : Target variant count per campaign}';
    protected $description = 'Dispatch a top-up job for every campaign under the target variant count.';

    public function handle(): int
    {
        $target = (int) $this->option('target');

        // Postgres rejects HAVING on a withCount alias, so filter via a
        // raw count subquery in the WHERE clause instead.
        $candidates = Campaign::query()
            ->whereNotNull('workspace_id') // skip pre-signup preview campaigns
            ->whereRaw('(SELECT COUNT(*) FROM ad_variants WHERE ad_variants.campaign_id = campaigns.id) < ?', [$target])
            ->withCount('variants')
            ->get();

        $this->info("Top-up scan: {$candidates->count()} campaign(s) under {$target} variants");

        foreach ($candidates as $campaign) {
            TopUpCampaignAdsJob::dispatch($campaign->id, $target);
            $this->line("  → dispatched campaign={$campaign->id} (currently {$campaign->variants_count}/{$target})");
        }

        return self::SUCCESS;
    }
}
