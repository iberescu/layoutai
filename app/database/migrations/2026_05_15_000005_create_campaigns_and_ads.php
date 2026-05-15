<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            // Nullable so pre-signup preview campaigns can be created and
            // re-parented when the user claims their account.
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('brand_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('status')->default('draft');
            $table->string('goal')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->timestamps();
        });

        Schema::create('ad_concepts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('concept');
            $table->string('ad_type')->default('brand');
            $table->jsonb('strategy_json')->default('{}');
            $table->timestamps();
        });

        Schema::create('ad_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('concept_id')->nullable()->constrained('ad_concepts')->nullOnDelete();
            $table->integer('size_width');
            $table->integer('size_height');
            $table->string('headline')->nullable();
            $table->string('subheadline')->nullable();
            $table->text('body')->nullable();
            $table->string('cta')->nullable();
            $table->longText('html')->nullable();
            $table->longText('css')->nullable();
            $table->string('layout_type')->nullable();
            $table->string('status')->default('generated');
            $table->string('source_type')->default('brand');
            $table->foreignId('news_event_id')->nullable();
            $table->string('policy_status')->default('pending');
            $table->jsonb('meta')->default('{}');
            $table->timestamps();
        });

        Schema::create('ad_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_variant_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->string('prompt_hash', 64)->index();
            $table->text('source_url')->nullable();
            $table->text('stored_url')->nullable();
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->integer('file_size_bytes')->default(0);
            $table->timestamps();
        });

        Schema::create('ad_renders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_variant_id')->constrained()->cascadeOnDelete();
            $table->string('format')->default('png');
            $table->text('asset_url')->nullable();
            $table->integer('file_size_bytes')->default(0);
            $table->integer('width');
            $table->integer('height');
            $table->string('render_status')->default('pending');
            $table->jsonb('validation_errors_json')->default('[]');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_renders');
        Schema::dropIfExists('ad_images');
        Schema::dropIfExists('ad_variants');
        Schema::dropIfExists('ad_concepts');
        Schema::dropIfExists('campaigns');
    }
};
