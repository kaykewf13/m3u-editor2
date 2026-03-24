<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->boolean('enable_vod_channels')->after('enable_channels')
                ->default(false);
        });

        // Next, we need to set `enable_vod_channels` to true for any playlists that have `enable_channels` true
        // This will respect the existing `enable_channels` setting and ensure that VOD channels are enabled for any playlists that had channels enabled before this migration
        DB::table('playlists')
            ->where('enable_channels', true)
            ->update(['enable_vod_channels' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('enable_vod_channels');
        });
    }
};
