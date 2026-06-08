<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // Premium workspaces are exempt from the monthly free-credit cap.
            $table->boolean('is_premium')->default(false);
            $table->timestamp('premium_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['is_premium', 'premium_until']);
        });
    }
};
