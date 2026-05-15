<?php

namespace App\Http\Controllers;

use App\Actions\CreateOnboardingSession;
use App\Jobs\CrawlWebsiteJob;
use App\Jobs\ExtractBrandJob;
use App\Jobs\GenerateAdConceptsJob;
use App\Jobs\GenerateAdImagePromptsJob;
use App\Jobs\GenerateAdImagesJob;
use App\Jobs\GenerateAdTemplatesJob;
use App\Jobs\RenderAdAssetsJob;
use App\Jobs\SummarizeBrandWithGeminiJob;
use App\Models\AdVariant;
use App\Models\Campaign;
use App\Models\OnboardingSession;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OnboardingController extends Controller
{
    public function start(Request $request, CreateOnboardingSession $action): JsonResponse
    {
        $data = $request->validate([
            'website_url'       => ['required', 'url', 'max:2048'],
            'business_location' => ['nullable', 'string', 'max:255'],
            'campaign_goal'     => ['nullable', 'string', Rule::in(['awareness','traffic','leads','sales'])],
            'logo'              => ['nullable', 'file', 'image', 'max:5120'],
        ]);

        $session = $action->handle($data, $request->file('logo'));

        Bus::chain([
            new CrawlWebsiteJob($session->id),
            new ExtractBrandJob($session->id),
            new SummarizeBrandWithGeminiJob($session->id),
            new GenerateAdConceptsJob($session->id),
            new GenerateAdImagePromptsJob($session->id),
            new GenerateAdImagesJob($session->id),
            new GenerateAdTemplatesJob($session->id),
            new RenderAdAssetsJob($session->id),
        ])->dispatch();

        return response()->json([
            'session'  => $session->uuid,
            'redirect' => route('create.processing', $session->uuid),
        ]);
    }

    public function processing(OnboardingSession $session): View
    {
        return view('pages.create.processing', compact('session'));
    }

    public function status(OnboardingSession $session): JsonResponse
    {
        return response()->json([
            'status'   => $session->status,
            'steps'    => $session->steps ?? [],
            'progress' => $session->progressFraction(),
        ]);
    }

    public function preview(OnboardingSession $session): View
    {
        $brand = $session->brandProfile;

        $campaign = Campaign::query()
            ->where('brand_profile_id', $brand?->id)
            ->whereNull('workspace_id')
            ->orWhere(function ($q) use ($session) {
                $q->whereHas('brandProfile', fn ($b) => $b->where('onboarding_session_id', $session->id));
            })
            ->latest()
            ->first();

        $variants = $campaign
            ? $campaign->variants()->with('renders')->limit(30)->get()
            : collect();

        return view('pages.create.preview', compact('session', 'brand', 'variants'));
    }

    public function claim(OnboardingSession $session): View
    {
        $brand = $session->brandProfile;

        return view('pages.create.claim', compact('session', 'brand'));
    }

    public function storeAccount(Request $request, OnboardingSession $session): RedirectResponse
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'email'        => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'     => ['required', 'string', 'min:8'],
            'company_name' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $session) {
            $user = User::create([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            $workspace = Workspace::create([
                'name'     => $data['company_name'] ?: ($session->brandProfile?->company_name ?: 'My workspace'),
                'slug'     => Str::slug(($data['company_name'] ?: $user->email) . '-' . Str::random(4)),
                'owner_id' => $user->id,
            ]);
            $workspace->members()->attach($user->id, ['role' => 'owner']);

            // Attach session + brand to workspace
            $session->update(['user_id' => $user->id, 'workspace_id' => $workspace->id]);
            if ($session->brandProfile) {
                $session->brandProfile->update(['workspace_id' => $workspace->id]);
            }

            // $500 promotional credit
            $workspace->creditLedger()->create([
                'amount_cents' => 50000,
                'type'         => 'promotional_grant',
                'description'  => 'Layout.ai $500 promotional ad credit',
                'expires_at'   => now()->addDays(90),
            ]);

            // Re-parent the existing preview campaign + variants to the new workspace.
            $existing = Campaign::query()
                ->whereNull('workspace_id')
                ->where('brand_profile_id', $session->brand_profile_id)
                ->latest('id')
                ->first();

            if ($existing) {
                $existing->update([
                    'workspace_id' => $workspace->id,
                    'name'         => 'First AI Display Campaign',
                ]);
            } else {
                Campaign::create([
                    'workspace_id'     => $workspace->id,
                    'brand_profile_id' => $session->brand_profile_id,
                    'name'             => 'First AI Display Campaign',
                    'status'           => 'draft',
                    'goal'             => $session->campaign_goal ?: 'awareness',
                ]);
            }

            Auth::login($user);
        });

        return redirect()->route('dashboard')->with('status', 'Your $500 credit is ready.');
    }
}
