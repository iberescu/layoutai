<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_profiles', function (Blueprint $table) {
            // Detected brand fonts mapped to the closest Google Fonts. Shape:
            //   { primary, secondary, google_primary, google_secondary,
            //     google_link, source } — consumed by TemplateAdRenderer.
            $table->jsonb('fonts_json')->default('{}');

            // Set by EcommerceDetector during the crawl. When true the pipeline
            // also generates 20 product remarketing ads from scraped products.
            $table->boolean('is_ecommerce')->default(false);
            $table->string('ecommerce_platform')->nullable(); // shopify|woocommerce|magento|generic
        });
    }

    public function down(): void
    {
        Schema::table('brand_profiles', function (Blueprint $table) {
            $table->dropColumn(['fonts_json', 'is_ecommerce', 'ecommerce_platform']);
        });
    }
};
