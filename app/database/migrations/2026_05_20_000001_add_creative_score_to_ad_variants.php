<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ad_variants', function (Blueprint $table) {
            // 0.00 — 100.00; nullable until the scoring job lands a result.
            $table->decimal('creative_score', 5, 2)->nullable()->index();
            // Raw output from the scorer (e.g. brain region activations) for
            // traceability and re-aggregation if we change the score formula.
            $table->jsonb('creative_score_meta')->nullable();
            $table->timestamp('creative_scored_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ad_variants', function (Blueprint $table) {
            $table->dropColumn(['creative_score', 'creative_score_meta', 'creative_scored_at']);
        });
    }
};
