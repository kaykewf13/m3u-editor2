<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('copilot_tool_calls', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('message_id')
                ->constrained('copilot_messages')
                ->cascadeOnDelete();
            $table->string('tool_name');
            $table->json('tool_input')->nullable();
            $table->longText('tool_output')->nullable();
            $table->string('status')->default('pending');
            $table->boolean('requires_approval')->default(false);
            $table->timestamps();

            $table->index(['message_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('copilot_tool_calls');
    }
};
