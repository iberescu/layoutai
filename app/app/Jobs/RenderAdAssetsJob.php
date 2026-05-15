<?php

namespace App\Jobs;

use App\Models\AdVariant;
use App\Models\OnboardingSession;
use App\Services\AdRenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RenderAdAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 2;

    public function __construct(public int $onboardingSessionId)
    {
        $this->onQueue('render');
    }

    public function handle(AdRenderService $renderer): void
    {
        $session = OnboardingSession::findOrFail($this->onboardingSessionId);
        $session->setStep('render', 'in_progress');

        $variants = AdVariant::whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $session->brand_profile_id))
            ->whereDoesntHave('renders', fn ($q) => $q->where('render_status', 'completed'))
            ->get();
        foreach ($variants as $variant) {
            $renderer->render($variant, 'png');
        }

        $session->update(['status' => 'preview_ready']);
        $session->setStep('render', 'completed', ['count' => $variants->count()]);
    }
}
