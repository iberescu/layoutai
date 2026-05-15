<?php

namespace App\Services;

use App\Models\PixelSite;
use Illuminate\Support\Facades\Http;

class PixelVerificationService
{
    public function verify(PixelSite $site): string
    {
        $eventRecent = $site->last_event_at && $site->last_event_at->gt(now()->subHours(24));
        if ($eventRecent) {
            $site->update(['status' => 'receiving_events']);
            return 'receiving_events';
        }

        $detected = false;
        try {
            $domain = $site->domain;
            $url    = (str_starts_with($domain, 'http') ? $domain : 'https://' . $domain);
            $body   = (string) Http::timeout(15)->get($url)->body();
            $detected = str_contains($body, 'data-layout-site="' . $site->site_id . '"')
                || str_contains($body, '/pixel.js');
        } catch (\Throwable) {
            // ignore
        }

        $status = $detected ? 'detected' : 'not_installed';
        $site->update(['status' => $status]);

        return $status;
    }
}
