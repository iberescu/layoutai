<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandProfile extends Model
{
    protected $fillable = [
        'workspace_id', 'onboarding_session_id', 'website_url', 'company_name',
        'industry', 'description', 'target_audience_json', 'brand_voice_json',
        'colors_json', 'visual_identity_json', 'proof_points_json',
        'ctas_json', 'compliance_risks_json', 'logo_asset_id',
    ];

    protected $casts = [
        'target_audience_json'   => 'array',
        'brand_voice_json'       => 'array',
        'colors_json'            => 'array',
        'visual_identity_json'   => 'array',
        'proof_points_json'      => 'array',
        'ctas_json'              => 'array',
        'compliance_risks_json'  => 'array',
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
}
