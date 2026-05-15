<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('type');
            $table->string('description')->nullable();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->timestamp('expires_at')->nullable();
        });

        Schema::create('news_event_hooks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('type');
            $table->string('location')->nullable();
            $table->date('date')->nullable();
            $table->decimal('relevance_score', 4, 2)->default(0);
            $table->decimal('risk_score', 4, 2)->default(0);
            $table->string('recommended_angle')->nullable();
            $table->jsonb('avoid')->default('[]');
            $table->jsonb('meta')->default('{}');
            $table->timestamps();
        });

        Schema::create('generation_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('onboarding_session_id')->nullable()->constrained('onboarding_sessions')->nullOnDelete();
            $table->string('kind');
            $table->string('status')->default('pending');
            $table->jsonb('input')->default('{}');
            $table->jsonb('output')->default('{}');
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('generation_jobs');
        Schema::dropIfExists('news_event_hooks');
        Schema::dropIfExists('credit_ledger');
    }
};
