<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->jsonb('xtream_fallback_urls')->nullable()->after('xtream_config');
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('xtream_fallback_urls');
        });
    }
};
