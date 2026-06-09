<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_sessions', function (Blueprint $table) {
            // ISO-3166-1 alpha-2 country the brand wants to run ads in.
            $table->string('ad_target_country', 2)->nullable();
        });
        Schema::table('brand_profiles', function (Blueprint $table) {
            $table->string('ad_target_country', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_sessions', fn (Blueprint $t) => $t->dropColumn('ad_target_country'));
        Schema::table('brand_profiles', fn (Blueprint $t) => $t->dropColumn('ad_target_country'));
    }
};
