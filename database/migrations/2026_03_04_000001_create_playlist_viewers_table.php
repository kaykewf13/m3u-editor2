<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlist_viewers', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('name');
            $table->boolean('is_admin')->default(false);
            $table->morphs('viewerable'); // viewerable_type, viewerable_id + index
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_viewers');
    }
};
