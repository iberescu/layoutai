<?php

namespace App\Jobs;

use App\Models\OnboardingSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pass-through chain step. With the parallelised pipeline, the actual
 * Gemini HTML calls happen inside per-variant BuildAdHtmlJob instances
 * triggered by GenerateAdImageJob. This job just marks the step started
 * so the polling UI shows accurate progress.
 */
class GenerateAdTemplatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;
    public int $tries   = 1;

    public function __construct(public int $onboardingSessionId)
    {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        $session = OnboardingSession::findOrFail($this->onboardingSessionId);
        $session->setStep('templates', 'in_progress');
    }
}
