<?php

namespace App\Services\Events;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Yahoo Finance chart endpoint (free, no auth) for S&P 500, BTC, Gold, Oil.
 * Each ticker yields one event hook describing the week's move so brand
 * messaging can lean optimistic ("market up — invest in growth") or
 * cautious ("market down — value, safe choices").
 */
class MarketEventSource
{
    private const TICKERS = [
        ['symbol' => '^GSPC',   'label' => 'S&P 500'],
        ['symbol' => 'BTC-USD', 'label' => 'Bitcoin'],
        ['symbol' => 'GC=F',    'label' => 'Gold'],
        ['symbol' => 'CL=F',    'label' => 'Oil'],
    ];

    public function fetch(): array
    {
        $out = [];
        foreach (self::TICKERS as $t) {
            try {
                $r = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (LayoutAIBot)'])
                    ->timeout(15)
                    ->retry(2, 800)
                    ->get('https://query1.finance.yahoo.com/v8/finance/chart/'.$t['symbol'], [
                        'range'    => '7d',
                        'interval' => '1d',
                    ]);
                if (! $r->successful()) continue;

                $closes = $r->json('chart.result.0.indicators.quote.0.close', []);
                $closes = array_values(array_filter($closes, fn ($c) => is_numeric($c)));
                if (count($closes) < 2) continue;

                $last = (float) end($closes);
                $first = (float) reset($closes);
                if ($first <= 0) continue;
                $changePct = (($last - $first) / $first) * 100;

                $event = $this->summarise($t['label'], $last, $changePct);
                if ($event) $out[] = $event;
            } catch (\Throwable $e) {
                Log::info("MarketEventSource {$t['symbol']} failed: ".$e->getMessage());
            }
        }
        return $out;
    }

    private function summarise(string $label, float $close, float $changePct): array
    {
        $sign     = $changePct >= 0 ? '+' : '';
        $rounded  = round($changePct, 1);
        $title    = "{$label} {$sign}{$rounded}% this week";

        $angle = match (true) {
            $changePct >=  3 => "Bullish week — investors confident. Lean into optimistic, growth, expansion, ambition copy.",
            $changePct >=  0 => "Mild gains — stable week. Lean into steady, reliable, dependable copy.",
            $changePct >= -3 => "Slight dip — cautious week. Lean into value, smart-money, savings-led copy.",
            default          => "Sharp drop — anxious week. Lean into resilience, safe-haven, essentials, value, comfort.",
        };

        return [
            'source'           => 'market',
            'external_id'      => 'market:'.strtolower($label).':'.date('Y-W'),
            'title'            => $title,
            'type'             => 'market',
            'location'         => null,
            'date'             => Carbon::now()->toDateString(),
            'expires_at'       => Carbon::now()->addDays(7),
            'relevance_score'  => 0.55,
            'risk_score'       => 0.05,
            'recommended_angle'=> $angle,
            'avoid'            => ['fear-mongering', 'doom', 'specific stock picks', 'financial advice'],
            'meta'             => [
                'close'      => round($close, 2),
                'change_pct' => $rounded,
            ],
        ];
    }
}
