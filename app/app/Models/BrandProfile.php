<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class BrandProfile extends Model
{
    protected $fillable = [
        'workspace_id', 'onboarding_session_id', 'website_url', 'company_name',
        'industry', 'description', 'target_audience_json', 'brand_voice_json',
        'colors_json', 'visual_identity_json', 'proof_points_json',
        'ctas_json', 'compliance_risks_json', 'logo_asset_id',
        'fonts_json', 'is_ecommerce', 'ecommerce_platform',
    ];

    protected $casts = [
        'target_audience_json'   => 'array',
        'brand_voice_json'       => 'array',
        'colors_json'            => 'array',
        'visual_identity_json'   => 'array',
        'proof_points_json'      => 'array',
        'ctas_json'              => 'array',
        'compliance_risks_json'  => 'array',
        'fonts_json'             => 'array',
        'is_ecommerce'           => 'boolean',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function onboardingSession(): BelongsTo
    {
        return $this->belongsTo(OnboardingSession::class);
    }

    public function logoAsset(): BelongsTo
    {
        return $this->belongsTo(UploadedAsset::class, 'logo_asset_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /** All ad variants generated for this brand (across its campaigns). */
    public function adVariants(): HasManyThrough
    {
        return $this->hasManyThrough(AdVariant::class, Campaign::class, 'brand_profile_id', 'campaign_id');
    }

    /** Best available logo for display: uploaded asset → crawl-extracted → none. */
    public function displayLogoUrl(): ?string
    {
        try {
            if ($url = $this->logoAsset?->url()) {
                return $url;
            }
        } catch (\Throwable) {
            // fall through
        }
        return $this->visual_identity_json['logo_url'] ?? null;
    }

    public function primaryColor(): string
    {
        return $this->visual_identity_json['primary_color']
            ?? $this->colors_json['primary']
            ?? '#2563EB';
    }

    public function accentColor(): string
    {
        return $this->visual_identity_json['accent_color']
            ?? $this->colors_json['accent']
            ?? '#7C3AED';
    }

    public function secondaryColor(): string
    {
        return $this->visual_identity_json['secondary_color']
            ?? $this->colors_json['secondary']
            ?? '#0F172A';
    }

    /**
     * Closest-matched Google Font for headlines/display. Falls back to a
     * neutral grotesque so templates always render with a real webfont.
     */
    public function headlineFont(): string
    {
        return $this->fonts_json['google_primary'] ?? 'Inter';
    }

    /**
     * Closest-matched Google Font for body/sub copy. Falls back to the
     * headline font when only one family was detected.
     */
    public function bodyFont(): string
    {
        return $this->fonts_json['google_secondary']
            ?? $this->fonts_json['google_primary']
            ?? 'Inter';
    }

    /**
     * A <link> tag loading the matched Google Fonts, ready to drop into a
     * template <head>. Empty string when no fonts were matched.
     */
    public function googleFontsLink(): string
    {
        if (! empty($this->fonts_json['google_link'])) {
            return $this->fonts_json['google_link'];
        }

        $families = array_values(array_unique(array_filter([
            $this->fonts_json['google_primary']   ?? 'Inter',
            $this->fonts_json['google_secondary'] ?? null,
        ])));
        if (empty($families)) {
            return '';
        }

        $query = collect($families)
            ->map(fn (string $f) => 'family=' . str_replace(' ', '+', $f) . ':wght@400;600;700;800')
            ->implode('&');

        return '<link rel="preconnect" href="https://fonts.googleapis.com">'
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link href="https://fonts.googleapis.com/css2?' . $query . '&display=swap" rel="stylesheet">';
    }
}
