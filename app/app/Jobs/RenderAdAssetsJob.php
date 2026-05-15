<?php

namespace App\Jobs;

use App\Models\AdVariant;
use App\Models\OnboardingSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Polls for the per-variant fan-out (BuildAdHtmlJob -> RenderAdJob) to
 * finish. The fan-out runs in parallel; this job sits in the chain so
 * the session is only flipped to preview_ready once everything's done.
 */
class RenderAdAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 1;

    public function __construct(public int $onboardingSessionId)
    {
        $this->onQueue('render');
    }

    public function handle(): void
    {
        $session = OnboardingSession::findOrFail($this->onboardingSessionId);
        $session->setStep('render', 'in_progress');

        $brandId = $session->brand_profile_id;
        $deadline = time() + 480; // 8 minute ceiling

        while (time() < $deadline) {
            $total = AdVariant::whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $brandId))->count();
            $done = AdVariant::whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $brandId))
                ->whereHas('renders', fn ($q) => $q->where('render_status', 'completed'))
                ->count();
            if ($total > 0 && $done >= $total) {
                break;
            }
            // Catch any variants whose HTML built but no render job got queued.
            $orphans = AdVariant::whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $brandId))
                ->whereNotNull('html')
                ->whereDoesntHave('renders')
                ->pluck('id');
            foreach ($orphans as $id) {
                RenderAdJob::dispatch($id);
            }
            sleep(2);
        }

        $finalDone = AdVariant::whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $brandId))
            ->whereHas('renders', fn ($q) => $q->where('render_status', 'completed'))
            ->count();
        $htmlDone = AdVariant::whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $brandId))
            ->whereNotNull('html')
            ->count();

        $session->setStep('templates', 'completed', ['count' => $htmlDone]);
        $session->update(['status' => 'preview_ready']);
        $session->setStep('render', 'completed', ['count' => $finalDone]);
    }
}
