<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_session_id')->constrained('onboarding_sessions')->cascadeOnDelete();
            $table->string('url');
            $table->string('status')->default('pending');
            $table->integer('limit')->default(25);
            $table->integer('depth')->default(2);
            $table->jsonb('raw_response')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('crawl_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crawl_job_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->string('title')->nullable();
            $table->text('markdown')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->jsonb('images')->default('[]');
            $table->jsonb('links')->default('[]');
            $table->timestamps();
        });

        Schema::create('uploaded_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('onboarding_session_id')->nullable()->constrained('onboarding_sessions')->nullOnDelete();
            $table->string('type');
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime')->nullable();
            $table->integer('size_bytes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploaded_assets');
        Schema::dropIfExists('crawl_pages');
        Schema::dropIfExists('crawl_jobs');
    }
};
