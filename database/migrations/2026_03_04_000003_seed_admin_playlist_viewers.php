<?php

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistViewer;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $adminEmail = config('dev.admin_emails')[0] ?? null;
        if (! $adminEmail) {
            return;
        }

        $adminUser = User::where('email', $adminEmail)->first();
        if (! $adminUser) {
            return;
        }

        $models = [
            Playlist::class,
            CustomPlaylist::class,
            MergedPlaylist::class,
            PlaylistAlias::class,
        ];

        foreach ($models as $modelClass) {
            $modelClass::query()->each(function ($record) use ($adminUser, $modelClass) {
                // Skip if Admin viewer already exists for this record
                $exists = PlaylistViewer::where('viewerable_type', $modelClass)
                    ->where('viewerable_id', $record->id)
                    ->where('is_admin', true)
                    ->exists();

                if (! $exists) {
                    PlaylistViewer::create([
                        'ulid' => (string) Str::ulid(),
                        'name' => $adminUser->name,
                        'is_admin' => true,
                        'viewerable_type' => $modelClass,
                        'viewerable_id' => $record->id,
                    ]);
                }
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty — removing seeded data on rollback is destructive
    }
};
