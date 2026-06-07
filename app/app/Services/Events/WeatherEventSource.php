<?php

namespace App\Services\Events;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Open-Meteo (free, no API key) 7-day forecast for a handful of seed cities.
 * Each city yields one event hook describing the dominant weather pattern of
 * the upcoming week — Gemini at generation time decides whether that lines up
 * with the brand (sunny week → solar / outdoor / refreshments, rainy week →
 * indoor / streaming / comfort food, etc.).
 */
class WeatherEventSource
{
    private const CITIES = [
        ['name' => 'New York',  'lat' => 40.71, 'lon' => -74.01],
        ['name' => 'London',    'lat' => 51.51, 'lon' =>   -0.13],
        ['name' => 'Bucharest', 'lat' => 44.43, 'lon' =>   26.10],
        ['name' => 'Tokyo',     'lat' => 35.68, 'lon' =>  139.69],
    ];

    public function fetch(): array
    {
        $out = [];
        foreach (self::CITIES as $city) {
            try {
                $r = Http::timeout(15)->retry(2, 800)->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude'      => $city['lat'],
                    'longitude'     => $city['lon'],
                    'daily'         => 'temperature_2m_max,temperature_2m_min,weathercode,precipitation_sum',
                    'forecast_days' => 7,
                    'timezone'      => 'auto',
                ]);
                if (! $r->successful()) continue;
                $daily = $r->json('daily', []);
                $event = $this->summarise($city['name'], $daily);
                if ($event) $out[] = $event;
            } catch (\Throwable $e) {
                Log::info("WeatherEventSource {$city['name']} failed: ".$e->getMessage());
            }
        }
        return $out;
    }

    private function summarise(string $city, array $daily): ?array
    {
        $temps  = $daily['temperature_2m_max']  ?? [];
        $rains  = $daily['precipitation_sum']   ?? [];
        $codes  = $daily['weathercode']         ?? [];
        if (empty($temps)) return null;

        $avgHigh    = round(array_sum($temps) / count($temps), 1);
        $totalRain  = round(array_sum($rains), 1);
        $sunnyDays  = count(array_filter($codes, fn ($c) => in_array($c, [0, 1, 2], true)));
        $rainyDays  = count(array_filter($codes, fn ($c) => $c >= 51 && $c <= 67)) +
                      count(array_filter($codes, fn ($c) => $c >= 80 && $c <= 82));

        // Pick the dominant story for the week.
        if ($avgHigh >= 28) {
            $title = "Heatwave forecast in {$city}";
            $angle = 'Refreshing, cooling, lightweight, outdoor — promote summer products, hydration, shade.';
        } elseif ($avgHigh <= 2) {
            $title = "Freezing week ahead in {$city}";
            $angle = 'Warm, cozy, indoor, comforting — promote insulation, hot drinks, indoor entertainment.';
        } elseif ($sunnyDays >= 5) {
            $title = "Sunny week in {$city}";
            $angle = 'Bright, optimistic, outdoor — promote solar, gardens, terraces, walks, sunscreen.';
        } elseif ($rainyDays >= 4 || $totalRain >= 25) {
            $title = "Heavy rain through the week in {$city}";
            $angle = 'Cozy, indoor, focused — promote streaming, delivery, books, indoor hobbies.';
        } else {
            $title = "Mild week in {$city}";
            $angle = 'Casual, everyday, balanced — promote routine essentials, fresh seasonal picks.';
        }

        return [
            'source'           => 'weather',
            'external_id'      => 'weather:'.strtolower($city).':'.date('Y-W'),
            'title'            => $title,
            'type'             => 'weather',
            'location'         => $city,
            'date'             => Carbon::now()->toDateString(),
            'expires_at'       => Carbon::now()->addDays(7),
            'relevance_score'  => 0.6,
            'risk_score'       => 0.02,
            'recommended_angle'=> $angle,
            'avoid'            => ['weather panic', 'climate disaster framing'],
            'meta'             => [
                'avg_high_c'  => $avgHigh,
                'total_rain'  => $totalRain,
                'sunny_days'  => $sunnyDays,
                'rainy_days'  => $rainyDays,
            ],
        ];
    }
}
