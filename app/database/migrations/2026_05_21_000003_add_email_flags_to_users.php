<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Track which lifecycle emails have been sent so we never duplicate.
            $table->timestamp('welcome_sent_at')->nullable()->after('is_admin');
            $table->timestamp('getting_started_sent_at')->nullable()->after('welcome_sent_at');
            $table->timestamp('campaign_reminder_sent_at')->nullable()->after('getting_started_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['welcome_sent_at', 'getting_started_sent_at', 'campaign_reminder_sent_at']);
        });
    }
};
