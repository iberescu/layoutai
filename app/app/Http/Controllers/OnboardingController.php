<?php

namespace App\Http\Controllers;

use App\Actions\CreateOnboardingSession;
use App\Jobs\CrawlWebsiteJob;
use App\Jobs\ExtractBrandJob;
use App\Jobs\GenerateAdImagePromptsJob;
use App\Jobs\GenerateAdImagesJob;
use App\Jobs\GenerateAdTemplatesJob;
use App\Jobs\GenerateProductAdsJob;
use App\Jobs\GenerateTemplateAdsJob;
use App\Jobs\SummarizeBrandWithGeminiJob;
use App\Mail\GettingStartedEmail;
use App\Mail\WelcomeEmail;
use App\Models\AdVariant;
use App\Models\Campaign;
use App\Models\LeadmakerCampaign;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OnboardingController extends Controller
{
    public function start(Request $request, CreateOnboardingSession $action): JsonResponse
    {
        // Pre-normalise the URL so users typing `usr.ro` (no scheme) don't trip
        // the 'url' validator. The action defaults the scheme to https.
        if (is_string($raw = $request->input('website_url')) && $raw !== '') {
            $raw = trim($raw);
            if (! preg_match('#^[a-z][a-z0-9+.\-]*://#i', $raw)) {
                $request->merge(['website_url' => 'https://' . ltrim($raw, '/')]);
            }
        }

        $data = $request->validate([
            'website_url'        => ['required', 'url', 'max:2048'],
            'ad_target_country'  => ['nullable', 'string', Rule::in(array_keys(config('countries')))],
            'campaign_goal'      => ['nullable', 'string', Rule::in(['awareness','traffic','leads','sales'])],
            'logo'              => ['nullable', 'file', 'image', 'max:5120'],
            'logo_colors'       => ['nullable', 'array', 'max:8'],
            'logo_colors.*'     => ['string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $session = $action->handle($data, $request->file('logo'));

        Bus::chain([
            new CrawlWebsiteJob($session->id),
            new ExtractBrandJob($session->id),
            // Single Gemini call covers brand summary + 10 ad concepts.
            new SummarizeBrandWithGeminiJob($session->id),
            // 20 pre-built-template ads (no Gemini) + 20 product ads when the
            // crawled site is an ecommerce shop.
            new GenerateTemplateAdsJob($session->id),
            new GenerateProductAdsJob($session->id),
            new GenerateAdImagePromptsJob($session->id),
            new GenerateAdImagesJob($session->id),
            // Finalizer: polls until every variant has HTML built, then
            // flips the session to preview_ready. PNG render no longer on
            // the critical path — the frontend renders the HTML directly.
            new GenerateAdTemplatesJob($session->id),
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
            'error'    => $session->error,
        ]);
    }

    /**
     * Live list of variants whose HTML has been built — used by the
     * preview page to swap placeholder tiles for the live <iframe srcdoc>
     * as soon as each variant's HTML lands, instead of waiting for the
     * whole batch. Field name "renders" kept for backward compatibility
     * with the client poller; payload now carries `html` per variant.
     */
    public function renders(OnboardingSession $session): JsonResponse
    {
        $brandId = $session->brand_profile_id;
        $rows = $brandId
            ? AdVariant::whereHas('campaign', fn ($q) => $q->where('brand_profile_id', $brandId))
                ->whereNotNull('html')
                ->get(['id', 'html'])
                ->map(fn ($v) => ['variant_id' => $v->id, 'html' => $v->html])
            : collect();
        return response()->json([
            'status'  => $session->status,
            'renders' => $rows,
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

        $user      = null;
        $workspace = null;

        DB::transaction(function () use ($data, $session, &$user, &$workspace) {
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

        // Lifecycle emails. Welcome fires immediately, getting-started waits an
        // hour so the user has a chance to poke around the dashboard first
        // (and so the inbox doesn't get two emails back-to-back). Both queued.
        try {
            $fresh = User::where('email', $data['email'])->first();
            if ($fresh) {
                Mail::to($fresh->email)->queue(new WelcomeEmail($fresh));
                $fresh->update(['welcome_sent_at' => now()]);

                Mail::to($fresh->email)->later(now()->addHour(), new GettingStartedEmail($fresh));
                $fresh->update(['getting_started_sent_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::warning('Lifecycle email dispatch failed: ' . $e->getMessage());
        }

        // Signup conversion → Meta (server-side CAPI). Flash a matching event id
        // so the browser pixel fires a deduped Lead on the dashboard. This is
        // what attributes the signup back to the FB/IG ad that drove it.
        try {
            $eventId = (string) Str::uuid();
            $email   = strtolower(trim($data['email']));
            app(\App\Services\MetaAdsService::class)->sendConversion(
                'Lead',
                array_filter([
                    'em'                => [hash('sha256', $email)],
                    'client_ip_address' => $request->ip(),
                    'client_user_agent' => $request->userAgent(),
                    'fbp'               => $request->cookie('_fbp'),
                    'fbc'               => $request->cookie('_fbc'),
                ]),
                ['currency' => 'USD', 'value' => 0],
                $eventId,
                route('create.claim', $session->uuid),
            );
            session()->flash('meta_lead_event_id', $eventId);
        } catch (\Throwable $e) {
            Log::warning('Meta CAPI Lead failed: ' . $e->getMessage());
        }

        // Record intent to create a Leadmaker acquisition campaign for the new
        // customer. We only insert a 'pending' row here; the actual API POST is
        // done by the leadmaker:sync-new-campaigns cron so signup never blocks
        // on it (and a failed create just retries).
        try {
            if ($user && $workspace) {
                $this->provisionLeadmakerCampaign($user, $workspace, $session);
            }
        } catch (\Throwable $e) {
            Log::warning('Leadmaker provisioning failed: ' . $e->getMessage());
        }

        return redirect()->route('dashboard')->with('status', 'Your $500 credit is ready.');
    }

    /**
     * Record intent to create a Leadmaker acquisition campaign for the newly
     * onboarded workspace: a 'pending' leadmaker_campaigns row (idempotent on
     * workspace_id) snapshotting the url / timezone / customer. The
     * leadmaker:sync-new-campaigns cron performs the actual API call.
     */
    private function provisionLeadmakerCampaign(User $user, Workspace $workspace, OnboardingSession $session): void
    {
        $brand    = $session->brandProfile;
        $url      = $brand?->website_url ?: $session->website_url;
        $country  = $session->ad_target_country ?: $brand?->ad_target_country;
        $timezone = \App\Services\LeadmakerService::timezoneForCountry($country);
        $company  = $workspace->name ?: ($brand?->company_name ?: $user->name);

        LeadmakerCampaign::firstOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'user_id'          => $user->id,
                'url'              => $url,
                'timezone'         => $timezone,
                'customer_name'    => $user->name,
                'customer_email'   => $user->email,
                'customer_company' => $company,
                'status'           => 'pending',
                'request_payload'  => [
                    'url'      => $url,
                    'timezone' => $timezone,
                    'customer' => [
                        'name'    => $user->name,
                        'email'   => $user->email,
                        'company' => $company,
                    ],
                ],
            ],
        );
    }
}
