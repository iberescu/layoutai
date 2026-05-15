<?php

namespace App\Jobs;

use App\Models\PixelSite;
use App\Services\PixelVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class VerifyPixelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $pixelSiteId)
    {
        $this->onQueue('default');
    }

    public function handle(PixelVerificationService $verifier): void
    {
        $site = PixelSite::find($this->pixelSiteId);
        if (! $site) {
            return;
        }
        $verifier->verify($site);
    }
}
