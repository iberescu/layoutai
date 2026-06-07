<?php

namespace App\Jobs;

use App\Models\AdVariant;
use App\Models\BrandProfile;
use App\Models\Campaign;
use App\Models\OnboardingSession;
use App\Services\BrandImageHarvester;
use App\Services\TemplateAdRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Builds the 20 pre-built-template ads for a campaign — deterministically,
 * with NO Gemini. Copy comes from the brand profile, imagery from the crawl
 * (BrandImageHarvester, logo-filtered + aspect-bucketed), colors + matched
 * Google Fonts + logo from the brand. Each ad's HTML is rendered locally by
 * TemplateAdRenderer and stored immediately, so the finalizer + scorer pick
 * them up alongside the 10 Gemini ads.
 */
class GenerateTemplateAdsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 1;

    public function __construct(public int $onboardingSessionId)
    {
        $this->onQueue('ai');
    }

    public function handle(TemplateAdRenderer $renderer, BrandImageHarvester $harvester): void
    {
        $session = OnboardingSession::findOrFail($this->onboardingSessionId);
        if ($session->status === 'failed' || ! $session->brand_profile_id) {
            $session->setStep('template_ads', 'skipped', ['reason' => 'session_failed']);
            return;
        }

        $brand = BrandProfile::find($session->brand_profile_id);
        $campaign = Campaign::where('brand_profile_id', $brand?->id)->latest('id')->first();
        if (! $brand || ! $campaign) {
            $session->setStep('template_ads', 'skipped', ['reason' => 'no_campaign']);
            return;
        }

        $session->setStep('template_ads', 'in_progress');

        // Harvested brand imagery, bucketed by aspect so each template gets a
        // photo that crops cleanly (logos/icons already filtered out).
        $byBucket = ['landscape' => [], 'portrait' => [], 'square' => []];
        foreach ($harvester->harvestFor($session, 14) as $img) {
            $bucket = $img['bucket'] ?? BrandImageHarvester::bucketFor($img['w'] ?? null, $img['h'] ?? null);
            if ($bucket && isset($byBucket[$bucket]) && ! empty($img['url'])) {
                $byBucket[$bucket][] = $img['url'];
            }
        }

        $logoUrl = $this->logoUrl($brand);
        $copy    = $this->copyPool($brand);
        $ctas    = $this->ctaPool($brand);

        $made = 0;
        foreach ($renderer->manifest() as $i => $tpl) {
            $id  = $tpl['id'];
            $w   = (int) $tpl['width'];
            $h   = (int) $tpl['height'];

            $imageUrl = $tpl['needs_image'] ? $this->pickImage($byBucket, $w, $h) : null;
            [$headline, $sub] = $this->copyFor($tpl, $copy, $i);

            $content = [
                'headline'    => $headline,
                'subheadline' => $sub,
                'cta'         => $this->ctaFor($tpl, $ctas, $i),
                'logo_url'    => $logoUrl,
                'image_url'   => $imageUrl,
                'price'       => null,
            ];

            try {
                $rendered = $renderer->render($id, $brand, $content);
            } catch (\Throwable $e) {
                \Log::warning("GenerateTemplateAdsJob: render {$id} failed: " . $e->getMessage());
                continue;
            }

            $platform = $w >= 1080 && in_array([$w, $h], [[1080, 1080], [1080, 1350], [1080, 1920], [1200, 630]], true)
                ? 'social' : 'display';

            AdVariant::create([
                'campaign_id' => $campaign->id,
                'concept_id'  => null,
                'size_width'  => $w,
                'size_height' => $h,
                'headline'    => $headline,
                'subheadline' => $sub ?: null,
                'cta'         => $content['cta'],
                'html'        => $rendered['html'],
                'css'         => $rendered['css'] ?: null,
                'layout_type' => $id,
                'style'       => 'template',
                'platform'    => $platform,
                'source_type' => 'template',
                'status'      => 'generated',
                'meta'        => [
                    'template_id'   => $id,
                    'primary_color' => $brand->primaryColor(),
                    'accent_color'  => $brand->accentColor(),
                    'image_url'     => $imageUrl,
                    'engine'        => 'template',
                ],
            ]);
            $made++;
        }

        $session->setStep('template_ads', 'completed', ['count' => $made]);
    }

    /** Pick a bucket-matched harvested image; fall back across buckets, else null (→ gradient). */
    private function pickImage(array &$byBucket, int $w, int $h): ?string
    {
        $want = BrandImageHarvester::bucketFor($w, $h) ?? 'square';
        // Preference order: exact bucket → square (universal) → any remaining.
        foreach ([$want, 'square', 'landscape', 'portrait'] as $bucket) {
            if (! empty($byBucket[$bucket])) {
                return array_shift($byBucket[$bucket]);
            }
        }
        return null;
    }

    private function logoUrl(BrandProfile $brand): ?string
    {
        try {
            if ($u = $brand->logoAsset?->url()) {
                return $u;
            }
        } catch (\Throwable) {
            // fall through
        }
        return $brand->visual_identity_json['logo_url'] ?? null;
    }

    /**
     * Deterministic copy candidates from the brand profile (no Gemini).
     * Returns ['headlines' => [...], 'subs' => [...]] — short, punchy first.
     *
     * @return array{headlines:array<int,string>,subs:array<int,string>}
     */
    private function copyPool(BrandProfile $brand): array
    {
        $company  = trim((string) $brand->company_name) ?: 'our brand';
        $industry = $this->sane(trim((string) $brand->industry));
        $desc     = $this->sane(trim((string) $brand->description));
        $proofs   = array_values(array_filter(array_map(
            fn ($p) => $this->sane(is_string($p) ? trim($p) : (is_array($p) ? trim((string) ($p['text'] ?? $p['label'] ?? '')) : '')),
            (array) $brand->proof_points_json
        )));

        // Headlines: SHORT, punchy lines first (brand-name + evergreen + short
        // proofs). The description is a sentence — it belongs in the sub, not
        // the headline, or it gets truncated mid-word.
        $shortProofs = array_filter($proofs, fn ($p) => mb_strlen($p) <= 38);
        $headlines = array_values(array_unique(array_filter(array_merge(
            [
                "Meet {$company}",
                $industry ? Str::ucfirst($industry) . ', done right' : '',
                'Designed around you',
                'Made to last',
            ],
            $shortProofs,
            [
                "Discover {$company}",
                'The smarter choice',
                'Quality you can feel',
            ]
        ))));

        // Subs: the description (sane) + proofs + brand line + evergreen.
        $subs = array_values(array_unique(array_filter(array_merge(
            [
                $desc ? $this->clip($desc, 96) : '',
                $industry && $company ? "{$company} — {$industry}." : '',
            ],
            array_map(fn ($p) => $this->clip($p, 96), array_slice($proofs, 0, 4)),
            ['Crafted for everyday life.', 'Trusted by people like you.']
        ))));

        return ['headlines' => $headlines ?: ['Designed around you'], 'subs' => $subs ?: ['']];
    }

    /** @return array{0:string,1:string} [headline, sub] sized to the format. */
    private function copyFor(array $tpl, array $copy, int $i): array
    {
        $heads = $copy['headlines'];
        $subs  = $copy['subs'];
        $h = $tpl['height'];
        $w = $tpl['width'];

        // Tiny / thin banners: short headline, no sub. Prefer a brand-name line.
        if ($h <= 110) {
            $short = collect($heads)->first(fn ($x) => mb_strlen($x) <= 22)
                ?? $this->clip($heads[$i % count($heads)], 22);
            return [$short, ''];
        }

        $headline = $heads[$i % count($heads)];
        // Keep medium rectangles from overflowing.
        if ($w * $h < 90000) {
            $headline = $this->clip($headline, 42);
        }
        $sub = $subs[$i % count($subs)] ?? '';
        return [$headline, $sub];
    }

    /** @return array<int,string> */
    private function ctaPool(BrandProfile $brand): array
    {
        $ctas = array_values(array_filter(array_map(
            fn ($c) => is_string($c) ? trim($c) : '',
            (array) $brand->ctas_json
        )));
        // Fallback must NOT include "Shop now" — template ads run for non-shops
        // too, where a shopping CTA is off-tone. Product ads use Shop/Buy now.
        return $ctas ?: ['Learn more', 'Discover more', 'Get started', 'See how'];
    }

    private function ctaFor(array $tpl, array $ctas, int $i): string
    {
        $cta = $ctas[$i % count($ctas)];
        // Long CTAs break tiny banners — fall back to a short verb.
        if ($tpl['width'] <= 320 && $tpl['height'] <= 100 && mb_strlen($cta) > 8) {
            return 'Shop';
        }
        return $cta;
    }

    /**
     * Reject low-quality / hedging model output so it never becomes ad copy
     * (e.g. "Patagonia is a company that appears to offer products, though its
     * website is..."). Returns '' for junk so callers filter it out.
     */
    private function sane(string $text): string
    {
        $t = trim($text);
        if ($t === '') return '';
        $low = mb_strtolower($t);
        foreach ([
            'appears to', 'does not', 'the website', 'this website', 'i cannot', "i'm sorry",
            'unable to', 'no information', 'not specify', 'as an ai', 'content does not',
            'cannot determine', 'i do not', 'unknown', 'n/a', 'lorem ipsum', 'undefined',
            'no primary', 'no accent', 'is a company that',
        ] as $bad) {
            if (str_contains($low, $bad)) return '';
        }
        return $t;
    }

    private function clip(string $text, int $max): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        // Break on a word boundary, not mid-word.
        $cut = mb_substr($text, 0, $max - 1);
        $sp  = mb_strrpos($cut, ' ');
        if ($sp !== false && $sp >= $max * 0.6) {
            $cut = mb_substr($cut, 0, $sp);
        }
        return rtrim($cut, " ,.;:—-") . '…';
    }
}
