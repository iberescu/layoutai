<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('news_event_hooks', function (Blueprint $table) {
            // 'weather' | 'market' | 'tech' | 'holiday' | 'manual'
            $table->string('source')->nullable()->after('type');
            // External feed dedup key — e.g. HN story id, holiday date+slug.
            $table->string('external_id')->nullable()->after('source');
            $table->timestamp('expires_at')->nullable()->after('date');
            $table->unique(['source', 'external_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('news_event_hooks', function (Blueprint $table) {
            $table->dropUnique(['source', 'external_id']);
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['source', 'external_id', 'expires_at']);
        });
    }
};
