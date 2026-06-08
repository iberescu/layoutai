<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdVariant;
use App\Models\BrandProfile;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin CRM — every registered brand: logo, site, brand summary, ads generated,
 * remaining credit, account status. Paginated + searchable/filterable.
 */
class BrandController extends Controller
{
    public function index(Request $request): View
    {
        $query = BrandProfile::query()
            ->with(['workspace', 'logoAsset', 'onboardingSession'])
            ->withCount('adVariants')
            ->latest();

        if ($q = trim($request->string('q')->toString())) {
            $query->where(function ($w) use ($q) {
                $w->where('company_name', 'ilike', "%{$q}%")
                  ->orWhere('website_url', 'ilike', "%{$q}%")
                  ->orWhere('industry', 'ilike', "%{$q}%");
            });
        }

        match ($request->string('filter')->toString()) {
            'claimed'    => $query->whereNotNull('workspace_id'),
            'prospect'   => $query->whereNull('workspace_id'),
            'ecommerce'  => $query->where('is_ecommerce', true),
            'premium'    => $query->whereHas('workspace', fn ($w) => $w->where('is_premium', true)),
            default      => null,
        };

        $brands = $query->paginate(20)->appends($request->query());

        $stats = [
            'total'    => BrandProfile::count(),
            'claimed'  => BrandProfile::whereNotNull('workspace_id')->count(),
            'shops'    => BrandProfile::where('is_ecommerce', true)->count(),
            'ads'      => AdVariant::count(),
        ];

        return view('pages.admin.brands.index', compact('brands', 'stats'));
    }
}
