<?php

namespace App\Http\Controllers;

use App\Models\AdVariant;
use App\Models\PixelSite;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $workspace = Auth::user()->primaryWorkspace();
        $campaign = $workspace?->campaigns()->with('brandProfile')->latest()->first();

        $generatedCount    = $campaign ? $campaign->variants()->count() : 0;
        $needsReviewCount  = $campaign ? $campaign->variants()->where('status', 'needs_review')->count() : 0;
        $pixelStatus       = $workspace?->id
            ? optional(PixelSite::where('workspace_id', $workspace->id)->first())->status ?? 'not_installed'
            : 'not_installed';

        // Pick 3 same-shape variants for the campaign card thumbnail strip
        // so the row lines up cleanly. Prefer squares (300×250, 336×280,
        // 250×250) because they preview the cleanest in a 3-up grid; fall
        // back to whatever's available.
        $sampleVariants = collect();
        if ($campaign) {
            $built = $campaign->variants()->whereNotNull('html')->get();
            $squares = $built->filter(function ($v) {
                $a = $v->size_height > 0 ? $v->size_width / $v->size_height : 1;
                return $a >= 0.85 && $a <= 1.5; // 300×250, 336×280, 250×250
            });
            $sampleVariants = $squares->shuffle()->take(3)->values();
            // Top up from any remaining if we couldn't find 3 squares.
            if ($sampleVariants->count() < 3) {
                $rest = $built->whereNotIn('id', $sampleVariants->pluck('id'))->shuffle()->take(3 - $sampleVariants->count());
                $sampleVariants = $sampleVariants->concat($rest)->values();
            }
        }

        return view('pages.dashboard.index', [
            'workspace'         => $workspace,
            'campaign'          => $campaign,
            'creditBalanceCents'=> $workspace?->creditBalanceCents() ?? 0,
            'generatedCount'    => $generatedCount,
            'needsReviewCount'  => $needsReviewCount,
            'pixelStatus'       => $pixelStatus,
            'sampleVariants'    => $sampleVariants,
        ]);
    }
}
