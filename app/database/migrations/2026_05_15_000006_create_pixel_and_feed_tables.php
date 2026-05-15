<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pixel_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('site_id')->unique();
            $table->string('domain');
            $table->string('status')->default('not_installed');
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pixel_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pixel_site_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->jsonb('payload')->default('{}');
            $table->string('referrer')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });

        Schema::create('conversion_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pixel_site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_variant_id')->nullable()->constrained('ad_variants')->nullOnDelete();
            $table->string('type');
            $table->bigInteger('value_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->jsonb('meta')->default('{}');
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('url')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->timestamps();
        });

        Schema::create('product_feed_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_feed_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->string('product_url')->nullable();
            $table->bigInteger('price_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('availability')->nullable();
            $table->jsonb('raw')->default('{}');
            $table->timestamps();
            $table->unique(['product_feed_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_feed_items');
        Schema::dropIfExists('product_feeds');
        Schema::dropIfExists('conversion_events');
        Schema::dropIfExists('pixel_events');
        Schema::dropIfExists('pixel_sites');
    }
};
