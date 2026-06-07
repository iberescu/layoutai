<?php

namespace App\Services;

use App\Models\NewsEventHook;
use Carbon\CarbonImmutable;

class NewsEventService
{
    public function eligibleFor(string $location, float $maxRisk = 0.4, int $limit = 10): array
    {
        $events = NewsEventHook::query()
            // Match user's location OR location-agnostic hooks (market, tech, etc.).
            ->where(function ($w) use ($location) {
                if ($location) {
                    $w->where('location', $location)->orWhereNull('location');
                } else {
                    $w->whereNull('location');
                }
            })
            ->where('risk_score', '<=', $maxRisk)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('relevance_score')
            ->limit($limit)
            ->get();

        if ($events->isEmpty()) {
            return $this->seasonal($location);
        }

        return $events->map(fn ($e) => [
            'title'             => $e->title,
            'type'              => $e->type,
            'source'            => $e->source,
            'location'          => $e->location,
            'date'              => $e->date?->toDateString(),
            'relevance_score'   => (float) $e->relevance_score,
            'risk_score'        => (float) $e->risk_score,
            'recommended_angle' => $e->recommended_angle,
            'avoid'             => $e->avoid ?? [],
            'meta'              => $e->meta ?? [],
        ])->all();
    }

    private function seasonal(string $location = ''): array
    {
        $now = CarbonImmutable::now();
        return [
            [
                'title'             => 'End of quarter',
                'type'              => 'seasonal',
                'location'          => $location,
                'date'              => $now->endOfQuarter()->toDateString(),
                'relevance_score'   => 0.6,
                'risk_score'        => 0.05,
                'recommended_angle' => 'Calm, focused, productivity-oriented creative',
                'avoid'             => ['fear', 'panic'],
            ],
            [
                'title'             => 'Weekend mood',
                'type'              => 'cultural',
                'location'          => $location,
                'date'              => $now->endOfWeek()->toDateString(),
                'relevance_score'   => 0.55,
                'risk_score'        => 0.02,
                'recommended_angle' => 'Warm, relaxed, friendly creative',
                'avoid'             => [],
            ],
        ];
    }
}
