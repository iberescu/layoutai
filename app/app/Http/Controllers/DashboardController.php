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
        $campaign = $workspace?->campaigns()->latest()->first();

        $generatedCount    = $campaign ? $campaign->variants()->count() : 0;
        $needsReviewCount  = $campaign ? $campaign->variants()->where('status', 'needs_review')->count() : 0;
        $pixelStatus       = $workspace?->id
            ? optional(PixelSite::where('workspace_id', $workspace->id)->first())->status ?? 'not_installed'
            : 'not_installed';

        return view('pages.dashboard.index', [
            'workspace'         => $workspace,
            'campaign'          => $campaign,
            'creditBalanceCents'=> $workspace?->creditBalanceCents() ?? 0,
            'generatedCount'    => $generatedCount,
            'needsReviewCount'  => $needsReviewCount,
            'pixelStatus'       => $pixelStatus,
        ]);
    }
}
