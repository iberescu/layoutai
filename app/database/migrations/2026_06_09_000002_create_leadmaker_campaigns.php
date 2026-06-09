<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One Leadmaker acquisition campaign per onboarded workspace. The row is
        // inserted 'pending' at signup; the leadmaker:sync-new-campaigns cron does
        // the actual POST and fills in external_id + token.
        Schema::create('leadmaker_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Identifiers returned by POST /api/campaigns — used to poll status.
            $table->string('external_id')->nullable()->index();
            $table->string('token')->nullable();
            // Snapshot of what we send (so the cron has a stable payload).
            $table->text('url')->nullable();
            $table->string('timezone')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_company')->nullable();
            // Local lifecycle: pending → active (created) → failed (gave up).
            $table->string('status')->default('pending')->index();
            $table->jsonb('request_payload')->default('{}');
            $table->jsonb('response_payload')->default('{}');
            $table->string('last_status')->nullable();   // last campaign status seen
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
            // Idempotency: never create two campaigns for the same workspace.
            $table->unique('workspace_id');
        });

        // Append-only daily snapshots of each campaign's status response. This is
        // the table the daily GET .../status data is saved into.
        Schema::create('leadmaker_campaign_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leadmaker_campaign_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable()->index();
            $table->string('status')->nullable();
            $table->jsonb('payload')->default('{}');   // full status JSON response
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            // Explicit name — the auto-generated one exceeds Postgres's 63-char
            // identifier limit and would be silently truncated.
            $table->index(['leadmaker_campaign_id', 'fetched_at'], 'lm_campaign_statuses_campaign_fetched_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadmaker_campaign_statuses');
        Schema::dropIfExists('leadmaker_campaigns');
    }
};
