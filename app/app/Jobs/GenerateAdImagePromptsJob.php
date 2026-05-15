<?php

namespace App\Jobs;

use App\Models\OnboardingSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateAdImagePromptsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $onboardingSessionId)
    {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        // Concepts already include image_prompts; this step exists for clarity in the
        // queue chain and to apply policy/safety rewrites if needed.
        $session = OnboardingSession::findOrFail($this->onboardingSessionId);
        $session->setStep('image_prompts', 'completed');
    }
}
