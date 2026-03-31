<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->dropColumn(['plex_dvr_device_key', 'plex_dvr_playlist_uuid']);
            $table->json('plex_dvr_tuners')->nullable()->after('plex_machine_id');
        });
    }

    public function down(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->dropColumn('plex_dvr_tuners');
            $table->string('plex_dvr_device_key')->nullable()->after('plex_machine_id');
            $table->string('plex_dvr_playlist_uuid')->nullable()->after('plex_dvr_device_key');
        });
    }
};
