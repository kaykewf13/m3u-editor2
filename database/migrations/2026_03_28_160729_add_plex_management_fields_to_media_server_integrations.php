<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->boolean('plex_management_enabled')->default(false)->after('auto_fetch_metadata');
            $table->string('plex_dvr_id')->nullable()->after('plex_management_enabled');
            $table->string('plex_machine_id')->nullable()->after('plex_dvr_id');
        });
    }

    public function down(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->dropColumn([
                'plex_management_enabled',
                'plex_dvr_id',
                'plex_machine_id',
            ]);
        });
    }
};
