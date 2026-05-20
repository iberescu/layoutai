<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class CampaignController extends Controller
{
    public function show(Request $request, Campaign $campaign): View
    {
        abort_unless($campaign->workspace_id === Auth::user()->primaryWorkspace()?->id, 403);

        $brand = $campaign->brandProfile;

        $query = $campaign->variants();

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($size = $request->string('size')->toString()) {
            [$w, $h] = explode('x', $size);
            $query->where('size_width', (int) $w)->where('size_height', (int) $h);
        }

        // Sort: 'score' = highest creative score first (nulls last); else id.
        $sort = $request->string('sort')->toString();
        if ($sort === 'score') {
            $query->orderByRaw('creative_score DESC NULLS LAST')->orderBy('id');
        } else {
            $query->orderBy('id');
        }

        $variants = $query->paginate(24)->appends($request->query());

        $counts = $campaign->variants()
            ->selectRaw('status, COUNT(*) AS c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->toArray();

        $scoreStats = [
            'total'  => $campaign->variants()->count(),
            'scored' => $campaign->variants()->whereNotNull('creative_score')->count(),
            'avg'    => $campaign->variants()->whereNotNull('creative_score')->avg('creative_score'),
            'top'    => $campaign->variants()->whereNotNull('creative_score')->max('creative_score'),
        ];

        return view('pages.dashboard.campaign', compact('campaign', 'brand', 'variants', 'counts', 'scoreStats', 'sort'));
    }
}
