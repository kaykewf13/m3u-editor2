<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extension_plugins', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('hooks');
            $table->json('schema_definition')->nullable()->after('permissions');
            $table->string('trust_state')->default('pending_review')->after('installation_status');
            $table->text('trust_reason')->nullable()->after('trust_state');
            $table->timestamp('trusted_at')->nullable()->after('trust_reason');
            $table->foreignId('trusted_by_user_id')->nullable()->after('trusted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('blocked_at')->nullable()->after('trusted_by_user_id');
            $table->foreignId('blocked_by_user_id')->nullable()->after('blocked_at')->constrained('users')->nullOnDelete();
            $table->string('integrity_status')->default('unknown')->after('validation_status');
            $table->string('manifest_hash', 64)->nullable()->after('integrity_status');
            $table->string('entrypoint_hash', 64)->nullable()->after('manifest_hash');
            $table->string('plugin_hash', 64)->nullable()->after('entrypoint_hash');
            $table->json('trusted_hashes')->nullable()->after('plugin_hash');
            $table->timestamp('integrity_verified_at')->nullable()->after('trusted_hashes');

            $table->index(['trust_state', 'integrity_status'], 'extension_plugins_trust_integrity_idx');
        });
    }

    public function down(): void
    {
        Schema::table('extension_plugins', function (Blueprint $table) {
            $table->dropIndex('extension_plugins_trust_integrity_idx');
            $table->dropConstrainedForeignId('blocked_by_user_id');
            $table->dropConstrainedForeignId('trusted_by_user_id');
            $table->dropColumn([
                'permissions',
                'schema_definition',
                'trust_state',
                'trust_reason',
                'trusted_at',
                'blocked_at',
                'integrity_status',
                'manifest_hash',
                'entrypoint_hash',
                'plugin_hash',
                'trusted_hashes',
                'integrity_verified_at',
            ]);
        });
    }
};
