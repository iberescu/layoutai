<?php

namespace App\Jobs;

use App\Models\AdVariant;
use App\Services\AdRenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RenderAdJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 90;
    public int $tries   = 2;

    public function __construct(public int $variantId)
    {
        $this->onQueue('render');
    }

    public function handle(AdRenderService $renderer): void
    {
        $variant = AdVariant::with('renders')->find($this->variantId);
        if (! $variant) {
            return;
        }
        if ($variant->renders()->where('render_status', 'completed')->exists()) {
            return; // already rendered
        }
        $renderer->render($variant, 'png');
    }
}
