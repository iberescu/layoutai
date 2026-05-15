<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('onboarding_session_id')->nullable()->constrained('onboarding_sessions')->nullOnDelete();
            $table->string('website_url');
            $table->string('company_name')->nullable();
            $table->string('industry')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('target_audience_json')->default('{}');
            $table->jsonb('brand_voice_json')->default('{}');
            $table->jsonb('colors_json')->default('{}');
            $table->jsonb('visual_identity_json')->default('{}');
            $table->jsonb('proof_points_json')->default('[]');
            $table->jsonb('ctas_json')->default('[]');
            $table->jsonb('compliance_risks_json')->default('[]');
            $table->foreignId('logo_asset_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_profiles');
    }
};
