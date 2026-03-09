<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viewer_watch_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_viewer_id')->constrained('playlist_viewers')->cascadeOnDelete();
            $table->enum('content_type', ['live', 'vod', 'episode']);
            $table->unsignedBigInteger('stream_id'); // Xtream stream_id for live/vod; episode id for series
            $table->unsignedBigInteger('series_id')->nullable(); // for 'episode' content_type
            $table->unsignedSmallInteger('season_number')->nullable(); // for 'episode' content_type
            $table->unsignedInteger('position_seconds')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('completed')->default(false);
            $table->unsignedInteger('watch_count')->default(1); // primarily for live TV sorting
            $table->timestamp('last_watched_at')->useCurrent();
            $table->timestamps();

            $table->unique(['playlist_viewer_id', 'content_type', 'stream_id']);
            $table->index('playlist_viewer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viewer_watch_progress');
    }
};
