<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ad_images', function (Blueprint $table) {
            // 0-100 percentage of the smartcrop focal point on each axis.
            // The HTML pipeline applies these as `object-position: X% Y%`
            // so when the iframe crops the image (object-fit: cover) the
            // subject stays in frame for every ad aspect ratio.
            $table->decimal('focal_x', 5, 2)->nullable()->after('stored_url');
            $table->decimal('focal_y', 5, 2)->nullable()->after('focal_x');
        });
    }

    public function down(): void
    {
        Schema::table('ad_images', function (Blueprint $table) {
            $table->dropColumn(['focal_x', 'focal_y']);
        });
    }
};
