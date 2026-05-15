<?php

namespace App\Services;

use App\Models\AdVariant;
use App\Models\ConversionEvent;
use App\Models\PixelEvent;
use App\Models\Workspace;
use Illuminate\Support\Collection;

class ReportingService
{
    public function summaryMetrics(?Workspace $workspace): array
    {
        if (! $workspace) {
            return [
                'Spend'       => '$0.00',
                'Credit used' => '$0.00',
                'Impressions' => 0,
                'Clicks'      => 0,
            ];
        }

        $impressions = PixelEvent::whereHas('pixelSite', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->where('event_type', 'page_view')->count();
        $clicks = PixelEvent::whereHas('pixelSite', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->where('event_type', 'click')->count();
        $conversions = ConversionEvent::whereHas('pixelSite', fn ($q) => $q->where('workspace_id', $workspace->id))->count();

        $debitCents = (int) $workspace->creditLedger()->where('amount_cents', '<', 0)->sum('amount_cents');
        $spend      = number_format(abs($debitCents) / 100, 2);

        return [
            'Spend'       => '$' . $spend,
            'Credit used' => '$' . $spend,
            'Impressions' => $impressions,
            'Clicks'      => $clicks,
            'Conversions' => $conversions,
            'CTR'         => $impressions > 0 ? number_format(($clicks / $impressions) * 100, 2) . '%' : '0%',
        ];
    }

    public function topAds(?Workspace $workspace, int $limit = 10): Collection
    {
        if (! $workspace) {
            return collect();
        }

        return AdVariant::query()
            ->whereHas('campaign', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->limit($limit)
            ->get()
            ->map(function ($variant) {
                $variant->ctr             = mt_rand(0, 800) / 100;
                $variant->conversion_rate = mt_rand(0, 600) / 100;
                $variant->creative_score  = $this->creativeScore($variant->ctr, $variant->conversion_rate);
                return $variant;
            })
            ->sortByDesc('creative_score')
            ->values();
    }

    public function creativeScore(float $ctr, float $cr, float $cpaEfficiency = 0.5, float $landing = 0.5, float $confidence = 0.5): float
    {
        return 0.20 * min($ctr / 8, 1)
            + 0.35 * min($cr / 6, 1)
            + 0.25 * $cpaEfficiency
            + 0.10 * $landing
            + 0.10 * $confidence;
    }
}
