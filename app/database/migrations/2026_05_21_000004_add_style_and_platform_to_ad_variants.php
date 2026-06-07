<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ad_variants', function (Blueprint $table) {
            // 'standard' | 'creative' | 'animated' | 'social'
            // Drives which style sub-prompt GeminiHtmlAdService appends,
            // and lets the dashboard filter / colour-tag tiles by cohort.
            $table->string('style')->default('standard')->after('layout_type');
            // 'display' | 'social' — separates IAB display sizes from
            // Instagram/Facebook sizes so the iframe scaling logic + the
            // preview grid can render social tiles in their own row.
            $table->string('platform')->default('display')->after('style');
            $table->index('style');
            $table->index('platform');
        });
    }

    public function down(): void
    {
        Schema::table('ad_variants', function (Blueprint $table) {
            $table->dropIndex(['style']);
            $table->dropIndex(['platform']);
            $table->dropColumn(['style', 'platform']);
        });
    }
};
