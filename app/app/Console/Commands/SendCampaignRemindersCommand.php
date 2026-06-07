<?php

namespace App\Console\Commands;

use App\Mail\CampaignReminderEmail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCampaignRemindersCommand extends Command
{
    protected $signature   = 'layout:send-campaign-reminders {--dry-run : list users that would be nudged, but do nothing}';
    protected $description = 'Email users who signed up 3+ days ago and still haven\'t launched a campaign.';

    public function handle(): int
    {
        // Eligibility:
        //   - account is between 3 and 60 days old (after 60d we stop nagging)
        //   - never sent a campaign reminder
        //   - their workspaces have no campaign in 'running'/'scheduled' state
        //   - they still have a non-expired promotional credit
        $candidates = User::query()
            ->whereNull('campaign_reminder_sent_at')
            ->whereBetween('created_at', [now()->subDays(60), now()->subDays(3)])
            ->get();

        $sent = 0;
        foreach ($candidates as $user) {
            $hasLiveCampaign = $user->ownedWorkspaces()
                ->whereHas('campaigns', fn ($q) => $q->whereIn('status', ['running', 'scheduled']))
                ->exists();
            if ($hasLiveCampaign) {
                continue;
            }

            $credit = $user->ownedWorkspaces()
                ->with(['creditLedger' => fn ($q) => $q->where('type', 'promotional_grant')
                    ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))])
                ->get()
                ->flatMap->creditLedger
                ->first();
            if (! $credit) {
                continue;
            }

            $daysLeft = $credit->expires_at
                ? max(1, (int) Carbon::parse($credit->expires_at)->diffInDays(now()))
                : 90;

            if ($this->option('dry-run')) {
                $this->line("would nudge {$user->email} (id {$user->id}, expires in {$daysLeft}d)");
                continue;
            }

            try {
                Mail::to($user->email)->queue(new CampaignReminderEmail($user, $daysLeft));
                $user->update(['campaign_reminder_sent_at' => now()]);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning("Campaign reminder for {$user->email} failed: ".$e->getMessage());
            }
        }

        $this->info("Sent {$sent} reminder(s); scanned {$candidates->count()} candidate(s).");
        return self::SUCCESS;
    }
}
