<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(): View
    {
        $workspace = Auth::user()->primaryWorkspace();
        $brand     = $workspace?->brandProfiles()->latest()->first();

        return view('pages.dashboard.settings', compact('workspace', 'brand'));
    }
}
