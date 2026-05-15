<?php

namespace App\Http\Controllers;

use App\Models\PixelEvent;
use App\Models\PixelSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class PixelController extends Controller
{
    public function script(): Response
    {
        $endpoint = url('/p/event');
        $js = <<<JS
(function(){
    try {
        var s = document.currentScript;
        var siteId = s && s.getAttribute('data-layout-site');
        if (!siteId) return;
        var data = {
            site_id: siteId,
            event_type: 'page_view',
            url: window.location.href,
            title: document.title,
            referrer: document.referrer,
            ts: Date.now()
        };
        var url = '{$endpoint}';
        if (navigator.sendBeacon) {
            navigator.sendBeacon(url, new Blob([JSON.stringify(data)], { type: 'application/json' }));
        } else {
            var x = new XMLHttpRequest();
            x.open('POST', url, true);
            x.setRequestHeader('Content-Type', 'application/json');
            x.send(JSON.stringify(data));
        }
        window.layout = window.layout || function(){ /* track helper */ };
        window.layout.track = function(type, payload){
            var p = Object.assign({}, data, { event_type: type, payload: payload || {} });
            navigator.sendBeacon && navigator.sendBeacon(url, new Blob([JSON.stringify(p)], { type: 'application/json' }));
        };
    } catch (e) {}
})();
JS;

        return response($js, 200)
            ->header('Content-Type', 'application/javascript')
            ->header('Cache-Control', 'public, max-age=300');
    }

    public function event(Request $request): JsonResponse
    {
        $payload = $request->all();
        $siteId  = (string) ($payload['site_id'] ?? '');
        if ($siteId === '') {
            return response()->json(['ok' => false], 400);
        }
        $site = Cache::remember('pixel:site:' . $siteId, 60, fn () => PixelSite::where('site_id', $siteId)->first());
        if (! $site) {
            return response()->json(['ok' => false], 404);
        }

        PixelEvent::create([
            'pixel_site_id' => $site->id,
            'event_type'    => $payload['event_type'] ?? 'page_view',
            'payload'       => $payload,
            'referrer'      => $payload['referrer'] ?? null,
            'user_agent'    => substr((string) $request->userAgent(), 0, 255),
            'ip_address'    => $request->ip(),
            'occurred_at'   => now(),
        ]);

        $site->forceFill([
            'status'        => 'receiving_events',
            'last_event_at' => now(),
        ])->save();

        return response()->json(['ok' => true]);
    }
}
