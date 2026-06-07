<?php

namespace App\Console\Commands;

use App\Jobs\CrawlWebsiteJob;
use App\Jobs\ExtractBrandJob;
use App\Jobs\GenerateProductAdsJob;
use App\Jobs\GenerateTemplateAdsJob;
use App\Jobs\SummarizeBrandWithGeminiJob;
use App\Models\AdVariant;
use App\Models\BrandProfile;
use App\Models\OnboardingSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Runs the core onboarding workflow synchronously for ONE brand URL — crawl,
 * brand summary + font matching + ecommerce detection, 20 template ads, and
 * (for shops) 20 product ads — then renders a representative spread of the
 * resulting ads to PNG. Prints a single JSON result line. Used by the
 * 20-brand workflow test. Skips the async Gemini-ad image/HTML fan-out + the
 * 7-min finalizer poll (those are the pre-existing pipeline, not this feature).
 */
class WorkflowTestCommand extends Command
{
    protected $signature = 'templates:workflow-test {url} {--shots=6}';
    protected $description = 'Run the full template/product workflow for one brand and screenshot the ads';

    public function handle(): int
    {
        $url   = (string) $this->argument('url');
        $shots = (int) $this->option('shots');
        $host  = parse_url($url, PHP_URL_HOST) ?: Str::slug($url);
        $out   = storage_path('app/wf-brands/' . $host);
        @mkdir($out, 0775, true);

        $result = ['host' => $host, 'url' => $url, 'status' => 'ok', 'screenshots' => []];

        try {
            $session = OnboardingSession::create([
                'uuid' => (string) Str::uuid(), 'website_url' => $url,
                'campaign_goal' => 'sales', 'status' => 'processing', 'steps' => [],
            ]);

            CrawlWebsiteJob::dispatchSync($session->id);
            ExtractBrandJob::dispatchSync($session->id);
            SummarizeBrandWithGeminiJob::dispatchSync($session->id);

            $session->refresh();
            if ($session->status === 'failed' || ! $session->brand_profile_id) {
                $result['status'] = 'failed';
                $result['error']  = $session->error ?: 'brand step failed';
                $this->line(json_encode($result));
                return self::SUCCESS;
            }

            GenerateTemplateAdsJob::dispatchSync($session->id);
            GenerateProductAdsJob::dispatchSync($session->id);

            $brand = BrandProfile::find($session->brand_profile_id);
            $q = fn () => AdVariant::whereHas('campaign', fn ($x) => $x->where('brand_profile_id', $brand->id));

            $result += [
                'company'      => $brand->company_name,
                'is_ecommerce' => (bool) $brand->is_ecommerce,
                'platform'     => $brand->ecommerce_platform,
                'fonts'        => $brand->headlineFont() . ' / ' . $brand->bodyFont(),
                'colors'       => $brand->primaryColor() . ',' . $brand->accentColor(),
                'total'        => $q()->count(),
                'template'     => (clone $q())->where('source_type', 'template')->count(),
                'product'      => (clone $q())->where('source_type', 'product')->count(),
                'gemini'       => (clone $q())->where('source_type', 'brand')->count(),
                'expected'     => $brand->is_ecommerce ? '20 template + 20 product = 40' : '20 template + 10 gemini = 30',
            ];

            // Render a representative spread: distinct sizes, product ads first
            // for shops so the screenshots show the remarketing creative.
            $picks = $this->pickVariants($brand->id, $shots);
            foreach ($picks as $v) {
                $png = $this->screenshot($v->html, $v->size_width, $v->size_height);
                if ($png !== null) {
                    $name = $v->source_type . '_' . $v->layout_type . '.png';
                    file_put_contents($out . '/' . $name, $png);
                    $result['screenshots'][] = $out . '/' . $name;
                }
            }
        } catch (\Throwable $e) {
            $result['status'] = 'error';
            $result['error']  = $e->getMessage();
        }

        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    /** Pick a varied spread of variants (distinct sizes; product ads prioritised for shops). */
    private function pickVariants(int $brandId, int $shots): \Illuminate\Support\Collection
    {
        $all = AdVariant::whereHas('campaign', fn ($x) => $x->where('brand_profile_id', $brandId))
            ->whereNotNull('html')
            ->whereIn('source_type', ['template', 'product'])
            ->get(['id', 'html', 'size_width', 'size_height', 'layout_type', 'source_type']);

        // product first, then one per distinct size for variety
        $sorted = $all->sortByDesc(fn ($v) => $v->source_type === 'product' ? 1 : 0)->values();
        $seenSize = [];
        $picks = collect();
        foreach ($sorted as $v) {
            $key = $v->size_width . 'x' . $v->size_height . ':' . $v->source_type;
            if (isset($seenSize[$key])) continue;
            $seenSize[$key] = true;
            $picks->push($v);
            if ($picks->count() >= $shots) break;
        }
        return $picks;
    }

    private function screenshot(?string $html, int $w, int $h): ?string
    {
        if (! $html) return null;
        try {
            $r = Http::timeout(60)->post(rtrim((string) config('services.renderer.url'), '/') . '/render',
                ['html' => $html, 'width' => $w, 'height' => $h, 'format' => 'png']);
            return $r->successful() ? $r->body() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
