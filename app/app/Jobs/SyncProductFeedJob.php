<?php

namespace App\Jobs;

use App\Models\ProductFeed;
use App\Services\ProductFeedService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncProductFeedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $productFeedId)
    {
        $this->onQueue('default');
    }

    public function handle(ProductFeedService $service): void
    {
        $feed = ProductFeed::find($this->productFeedId);
        if (! $feed || ! $feed->url) {
            return;
        }
        if (in_array($feed->source, ['xml', 'google_merchant'], true)) {
            $service->ingestXml($feed, $feed->url);
            $feed->update(['last_synced_at' => now(), 'status' => 'synced']);
        }
    }
}
