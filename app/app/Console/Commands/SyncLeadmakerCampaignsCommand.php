<?php

namespace App\Console\Commands;

use App\Models\LeadmakerCampaign;
use App\Services\LeadmakerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Creates a Leadmaker campaign for every newly onboarded workspace. Onboarding
 * inserts a 'pending' leadmaker_campaigns row; this cron does the actual POST so
 * signup never blocks on the external call, and failed creates retry next tick.
 */
class SyncLeadmakerCampaignsCommand extends Command
{
    /** Give up (mark 'failed') after this many failed create attempts. */
    private const MAX_ATTEMPTS = 5;

    protected $signature = 'leadmaker:sync-new-campaigns
        {--limit=50 : Max pending campaigns to create this run}
        {--id= : Only process this leadmaker_campaigns row id}
        {--dry-run : Show what would be created without calling the API}';

    protected $description = 'Create a Leadmaker campaign for each newly onboarded workspace (pending rows).';

    public function handle(LeadmakerService $leadmaker): int
    {
        if (! $leadmaker->configured()) {
            $this->warn('LEADMAKER_API_KEY not set — skipping.');
            return self::SUCCESS;
        }

        $rows = LeadmakerCampaign::query()
            ->where('status', 'pending')
            ->whereNull('external_id')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->when($this->option('id'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $this->info("Leadmaker: {$rows->count()} pending campaign(s) to create.");

        $created = 0;
        $failed  = 0;

        foreach ($rows as $row) {
            $payload = $this->payloadFor($row);

            if (blank($payload['url'])) {
                $row->update(['status' => 'failed', 'error' => 'missing url']);
                $this->warn("  #{$row->id}: missing url — marked failed.");
                $failed++;
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  #{$row->id}: would POST " . json_encode($payload));
                continue;
            }

            $row->increment('attempts'); // record the attempt even if it throws

            try {
                $resp  = $leadmaker->createCampaign($payload);
                $extId = LeadmakerService::extractId($resp);
                $token = LeadmakerService::extractToken($resp);

                if (! $extId || ! $token) {
                    throw new \RuntimeException('create response missing id/token: ' . json_encode($resp));
                }

                $row->update([
                    'external_id'      => $extId,
                    'token'            => $token,
                    'request_payload'  => $payload,
                    'response_payload' => $resp,
                    'status'           => 'active',
                    'error'            => null,
                ]);

                $this->line("  #{$row->id}: created campaign {$extId}.");
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $isFinal = $row->attempts >= self::MAX_ATTEMPTS;
                $row->update([
                    'error'  => $e->getMessage(),
                    'status' => $isFinal ? 'failed' : 'pending',
                ]);
                Log::warning("Leadmaker create failed for workspace {$row->workspace_id}: " . $e->getMessage());
                $this->warn("  #{$row->id}: " . ($isFinal ? 'failed (gave up)' : 'error, will retry') . " — {$e->getMessage()}");
            }
        }

        $this->info("Done: {$created} created, {$failed} failed.");
        return self::SUCCESS;
    }

    /** Build the POST body from the snapshot stored at onboarding. */
    private function payloadFor(LeadmakerCampaign $row): array
    {
        return [
            'url'      => (string) $row->url,
            'timezone' => $row->timezone ?: 'UTC',
            'customer' => array_filter([
                'name'    => $row->customer_name,
                'email'   => $row->customer_email,
                'company' => $row->customer_company,
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }
}
