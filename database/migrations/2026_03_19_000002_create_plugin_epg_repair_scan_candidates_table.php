<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_epg_repair_scan_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extension_plugin_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_run_id')->nullable()->constrained('extension_plugin_runs')->nullOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playlist_id')->nullable()->constrained()->nullOnDelete();
            $table->string('playlist_name')->nullable();
            $table->string('issue')->nullable();
            $table->string('decision')->nullable();
            $table->foreignId('current_epg_channel_id')->nullable()->constrained('epg_channels')->nullOnDelete();
            $table->string('current_epg_channel_name')->nullable();
            $table->foreignId('current_epg_source_id')->nullable()->constrained('epgs')->nullOnDelete();
            $table->string('current_epg_source_name')->nullable();
            $table->foreignId('suggested_epg_channel_id')->nullable()->constrained('epg_channels')->nullOnDelete();
            $table->string('suggested_epg_channel_name')->nullable();
            $table->foreignId('suggested_epg_source_id')->nullable()->constrained('epgs')->nullOnDelete();
            $table->string('suggested_epg_source_name')->nullable();
            $table->foreignId('selected_epg_source_id')->nullable()->constrained('epgs')->nullOnDelete();
            $table->string('selected_epg_source_name')->nullable();
            $table->string('source_scope')->default('selected_only');
            $table->decimal('confidence', 8, 4)->nullable();
            $table->string('confidence_band')->nullable();
            $table->string('match_reason')->nullable();
            $table->boolean('repairable')->default(false);
            $table->json('source_candidates')->nullable();
            $table->string('apply_outcome')->nullable();
            $table->boolean('applied')->default(false);
            $table->string('review_status')->default('pending');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reviewed_by_user_name')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('last_apply_run_id')->nullable()->constrained('extension_plugin_runs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['extension_plugin_run_id', 'channel_id'], 'plugin_epg_repair_run_channel_unique');
            $table->index(['extension_plugin_run_id', 'review_status'], 'plugin_epg_repair_run_review_status_idx');
            $table->index(['extension_plugin_run_id', 'repairable'], 'plugin_epg_repair_run_repairable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_epg_repair_scan_candidates');
    }
};
