<?php

namespace App\Services\Events;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * date.nager.at public holidays (free, no auth). Picks up holidays in the
 * next 14 days for a handful of seed countries — brands can lean into gift-
 * giving, seasonal trips, family themes, etc. Skipped if the holiday looks
 * politically sensitive (independence days, religious-only observances).
 */
class HolidayEventSource
{
    private const COUNTRIES = ['US', 'GB', 'DE', 'RO', 'JP'];
    private const SKIP_KEYWORDS = ['independence', 'flag day', 'martyr', 'remembrance',
        'reformation', 'constitution', 'all saints'];

    public function fetch(): array
    {
        $out  = [];
        $year = Carbon::now()->year;

        foreach (self::COUNTRIES as $cc) {
            try {
                $r = Http::timeout(15)->retry(2, 800)
                    ->get("https://date.nager.at/api/v3/PublicHolidays/{$year}/{$cc}");
                if (! $r->successful()) continue;
                foreach ((array) $r->json() as $h) {
                    $event = $this->buildIfUpcoming($cc, $h);
                    if ($event) $out[] = $event;
                }
            } catch (\Throwable $e) {
                Log::info("HolidayEventSource {$cc} failed: ".$e->getMessage());
            }
        }
        return $out;
    }

    private function buildIfUpcoming(string $cc, array $h): ?array
    {
        $date = $h['date'] ?? null;
        if (! $date) return null;
        $d = Carbon::parse($date);
        $daysAway = (int) $d->diffInDays(now(), false) * -1; // positive = future
        if ($daysAway < 0 || $daysAway > 14) return null;

        $name = (string) ($h['name'] ?? '');
        $lower = strtolower($name);
        foreach (self::SKIP_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) return null;
        }

        return [
            'source'           => 'holiday',
            'external_id'      => 'holiday:'.$cc.':'.$date.':'.str($name)->slug(),
            'title'            => $name.' ('.$cc.', in '.$daysAway.'d)',
            'type'             => 'holiday',
            'location'         => $cc,
            'date'             => $date,
            'expires_at'       => $d->copy()->addDay(),
            'relevance_score'  => max(0.4, 0.85 - $daysAway * 0.03),
            'risk_score'       => 0.05,
            'recommended_angle'=> "Gift-giving, family, seasonal theme for {$name}. Skip tragedies, religious specifics, politics.",
            'avoid'            => ['politicising', 'religious specifics', 'mocking traditions'],
            'meta'             => ['country' => $cc, 'days_away' => $daysAway, 'fixed' => $h['fixed'] ?? null],
        ];
    }
}
