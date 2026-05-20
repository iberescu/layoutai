<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('onboarding_sessions', function (Blueprint $table) {
            // Hex colors extracted client-side from the uploaded logo at
            // submission time. Pinned to the Gemini prompt as the canonical
            // brand palette so generated ads don't clash with the logo.
            $table->jsonb('logo_colors_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_sessions', function (Blueprint $table) {
            $table->dropColumn('logo_colors_json');
        });
    }
};
