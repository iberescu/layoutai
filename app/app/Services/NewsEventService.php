<?php

namespace App\Services;

use App\Models\NewsEventHook;
use Carbon\CarbonImmutable;

class NewsEventService
{
    public function eligibleFor(string $location, float $maxRisk = 0.4, int $limit = 10): array
    {
        $events = NewsEventHook::query()
            ->when($location, fn ($q) => $q->where('location', $location)->orWhereNull('location'))
            ->where('risk_score', '<=', $maxRisk)
            ->orderByDesc('relevance_score')
            ->limit($limit)
            ->get();

        if ($events->isEmpty()) {
            return $this->seasonal($location);
        }

        return $events->map(fn ($e) => [
            'title'             => $e->title,
            'type'              => $e->type,
            'location'          => $e->location,
            'date'              => $e->date?->toDateString(),
            'relevance_score'   => (float) $e->relevance_score,
            'risk_score'        => (float) $e->risk_score,
            'recommended_angle' => $e->recommended_angle,
            'avoid'             => $e->avoid ?? [],
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
