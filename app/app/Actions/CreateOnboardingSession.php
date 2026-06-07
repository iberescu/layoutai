<?php

namespace App\Actions;

use App\Models\OnboardingSession;
use App\Models\UploadedAsset;
use App\Services\UrlResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreateOnboardingSession
{
    public function __construct(private readonly UrlResolver $urlResolver)
    {
    }

    public function handle(array $input, ?UploadedFile $logo = null): OnboardingSession
    {
        // Validate and de-dupe client-supplied logo colors (hex format only).
        $logoColors = collect($input['logo_colors'] ?? [])
            ->filter(fn ($c) => is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c))
            ->map(fn ($c) => strtolower($c))
            ->unique()
            ->take(8)
            ->values()
            ->all();

        // Fast cURL probe across scheme + www variants — picks the URL that
        // actually responds, so the slow Cloudflare crawl downstream doesn't
        // waste 60s on a www-mismatch or http-only host.
        $resolved = $this->urlResolver->resolve((string) $input['website_url']);

        $session = OnboardingSession::create([
            'website_url'       => $resolved,
            'business_location' => $input['business_location'] ?? null,
            'campaign_goal'     => $input['campaign_goal'] ?? null,
            'logo_colors_json'  => $logoColors ?: null,
            'status'            => 'queued',
            'steps'             => [
                'crawl'            => ['status' => 'pending'],
                'extract_brand'    => ['status' => 'pending'],
                'summarize_brand'  => ['status' => 'pending'],
                'concepts'         => ['status' => 'pending'],
                'image_prompts'    => ['status' => 'pending'],
                'images'           => ['status' => 'pending'],
                'templates'        => ['status' => 'pending'],
                'render'           => ['status' => 'pending'],
            ],
        ]);

        if ($logo) {
            $disk = config('filesystems.default', 'public');
            $path = $logo->storeAs(
                'onboarding/' . $session->uuid,
                Str::slug(pathinfo($logo->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $logo->getClientOriginalExtension(),
                $disk,
            );

            $asset = UploadedAsset::create([
                'onboarding_session_id' => $session->id,
                'type'                  => 'logo',
                'disk'                  => $disk,
                'path'                  => $path,
                'original_name'         => $logo->getClientOriginalName(),
                'mime'                  => $logo->getMimeType(),
                'size_bytes'            => $logo->getSize(),
            ]);

            $session->update(['logo_path' => $asset->path]);
        }

        return $session->fresh();
    }
}
