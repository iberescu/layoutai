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

        $query = $campaign->variants()->with('renders');

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($size = $request->string('size')->toString()) {
            [$w, $h] = explode('x', $size);
            $query->where('size_width', (int) $w)->where('size_height', (int) $h);
        }

        $variants = $query->paginate(24);

        $counts = $campaign->variants()
            ->selectRaw('status, COUNT(*) AS c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->toArray();

        return view('pages.dashboard.campaign', compact('campaign', 'brand', 'variants', 'counts'));
    }
}
