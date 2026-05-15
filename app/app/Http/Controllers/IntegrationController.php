<?php

namespace App\Http\Controllers;

use App\Models\PixelSite;
use App\Models\ProductFeed;
use App\Services\PixelVerificationService;
use App\Services\ProductFeedService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    public function index(): View
    {
        $workspaceId = Auth::user()->primaryWorkspace()->id;
        $pixelSite   = PixelSite::where('workspace_id', $workspaceId)->first();
        $productFeed = ProductFeed::where('workspace_id', $workspaceId)->first();

        return view('pages.dashboard.integrations', compact('pixelSite', 'productFeed'));
    }

    public function registerPixel(Request $request): RedirectResponse
    {
        $data = $request->validate(['domain' => ['required', 'string', 'max:255']]);

        PixelSite::create([
            'workspace_id' => Auth::user()->primaryWorkspace()->id,
            'domain'       => $data['domain'],
            'status'       => 'not_installed',
        ]);

        return redirect()->route('integrations.index')->with('status', 'Pixel snippet generated.');
    }

    public function verifyPixel(Request $request, PixelVerificationService $verifier): RedirectResponse
    {
        $site = PixelSite::findOrFail($request->integer('pixel_site_id'));
        abort_unless($site->workspace_id === Auth::user()->primaryWorkspace()->id, 403);

        $verifier->verify($site);

        return redirect()->route('integrations.index')->with('status', 'Verification check complete.');
    }

    public function connectFeed(Request $request, ProductFeedService $feed): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['required', 'in:csv,xml,google_merchant'],
            'url'    => ['nullable', 'url'],
            'file'   => ['nullable', 'file'],
        ]);

        $feed->connect(Auth::user()->primaryWorkspace(), $data, $request->file('file'));

        return redirect()->route('integrations.index')->with('status', 'Feed connected.');
    }
}
