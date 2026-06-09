<?php

namespace App\Console\Commands;

use App\Models\LeadmakerCampaign;
use App\Models\LeadmakerCampaignStatus;
use App\Services\LeadmakerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily: poll each active Leadmaker campaign's status and append a snapshot row
 * to leadmaker_campaign_statuses (the status data table). Transient errors are
 * logged and retried next day; the campaign stays 'active'.
 */
class FetchLeadmakerCampaignStatusesCommand extends Command
{
    protected $signature = 'leadmaker:fetch-statuses
        {--limit=1000 : Max active campaigns to poll this run}
        {--id= : Only poll this leadmaker_campaigns row id}
        {--dry-run : List what would be polled without calling the API}';

    protected $description = 'Fetch each active Leadmaker campaign status into leadmaker_campaign_statuses.';

    public function handle(LeadmakerService $leadmaker): int
    {
        if (! $leadmaker->configured()) {
            $this->warn('LEADMAKER_API_KEY not set — skipping.');
            return self::SUCCESS;
        }

        $rows = LeadmakerCampaign::query()
            ->where('status', 'active')
            ->whereNotNull('external_id')
            ->whereNotNull('token')
            ->when($this->option('id'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $this->info("Leadmaker: polling {$rows->count()} active campaign(s).");

        $ok     = 0;
        $failed = 0;

        foreach ($rows as $row) {
            if ($this->option('dry-run')) {
                $this->line("  #{$row->id}: would GET status for {$row->external_id}.");
                continue;
            }

            try {
                $data   = $leadmaker->campaignStatus((string) $row->external_id, (string) $row->token);
                $status = $this->extractStatus($data);

                LeadmakerCampaignStatus::create([
                    'leadmaker_campaign_id' => $row->id,
                    'external_id'           => $row->external_id,
                    'status'                => $status,
                    'payload'               => $data,
                    'fetched_at'            => now(),
                ]);

                $row->update([
                    'last_status'    => $status,
                    'last_synced_at' => now(),
                    'error'          => null,
                ]);

                $this->line("  #{$row->id}: " . ($status ?: 'ok') . ' — snapshot saved.');
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                $row->update(['error' => $e->getMessage()]);
                Log::warning("Leadmaker status fetch failed for campaign {$row->external_id}: " . $e->getMessage());
                $this->warn("  #{$row->id}: error — {$e->getMessage()}");
            }
        }

        $this->info("Done: {$ok} saved, {$failed} failed.");
        return self::SUCCESS;
    }

    /** Pull a top-level status string from the response, if the API provides one. */
    private function extractStatus(array $data): ?string
    {
        foreach ([
            $data['status'] ?? null,
            $data['campaign']['status'] ?? null,
            $data['data']['status'] ?? null,
            $data['state'] ?? null,
        ] as $v) {
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }
}
