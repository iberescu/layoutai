<?php

namespace App\Http\Controllers;

use App\Services\ReportingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ReportingController extends Controller
{
    public function __construct(private readonly ReportingService $reporting)
    {
    }

    public function index(): View
    {
        $workspace = Auth::user()->primaryWorkspace();

        return view('pages.dashboard.reporting', [
            'metrics' => $this->reporting->summaryMetrics($workspace),
            'topAds'  => $this->reporting->topAds($workspace),
        ]);
    }
}
