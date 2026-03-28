<?php

use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
});

it('preserves sort_order when a group is soft-deleted and restored', function () {
    $group = Group::factory()->create([
        'name' => 'Sports',
        'name_internal' => 'Sports',
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'sort_order' => 5,
        'enabled' => true,
        'custom' => false,
        'type' => 'live',
        'import_batch_no' => 'batch-1',
    ]);

    // Soft-delete the group (simulates sync removing it)
    $group->delete();

    expect($group->trashed())->toBeTrue();
    expect(Group::where('id', $group->id)->exists())->toBeFalse();
    expect(Group::withTrashed()->where('id', $group->id)->exists())->toBeTrue();

    // Simulate the group reappearing in a future sync — find with withTrashed and restore
    $restored = Group::withTrashed()->where([
        'name_internal' => 'Sports',
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'custom' => false,
        'type' => 'live',
    ])->first();

    expect($restored)->not->toBeNull();

    $restored->restore();
    $restored->update(['import_batch_no' => 'batch-2']);

    // Verify sort_order and enabled state are preserved
    expect((float) $restored->sort_order)->toBe(5.0);
    expect((bool) $restored->enabled)->toBeTrue();
    expect($restored->trashed())->toBeFalse();
});

it('does not find soft-deleted groups in normal queries', function () {
    $group = Group::factory()->create([
        'name' => 'Movies',
        'name_internal' => 'Movies',
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'custom' => false,
        'type' => 'vod',
        'import_batch_no' => 'batch-1',
    ]);

    $group->delete();

    // Normal query should not find the group
    expect(Group::where('playlist_id', $this->playlist->id)->count())->toBe(0);

    // withTrashed should find it
    expect(Group::withTrashed()->where('playlist_id', $this->playlist->id)->count())->toBe(1);
});

it('permanently deletes groups with forceDelete', function () {
    $group = Group::factory()->create([
        'name' => 'Custom Group',
        'name_internal' => 'Custom Group',
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'custom' => true,
        'type' => 'live',
        'import_batch_no' => 'batch-1',
    ]);

    $group->forceDelete();

    expect(Group::withTrashed()->where('id', $group->id)->exists())->toBeFalse();
});

it('cleans up old soft-deleted groups', function () {
    // Create a group soft-deleted 31 days ago
    $oldGroup = Group::factory()->create([
        'name' => 'Old Group',
        'name_internal' => 'Old Group',
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'custom' => false,
        'type' => 'live',
        'import_batch_no' => 'batch-1',
    ]);
    $oldGroup->delete();
    $oldGroup->update(['deleted_at' => now()->subDays(31)]);

    // Create a group soft-deleted 5 days ago
    $recentGroup = Group::factory()->create([
        'name' => 'Recent Group',
        'name_internal' => 'Recent Group',
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'custom' => false,
        'type' => 'live',
        'import_batch_no' => 'batch-1',
    ]);
    $recentGroup->delete();
    $recentGroup->update(['deleted_at' => now()->subDays(5)]);

    // Clean up groups soft-deleted >30 days ago
    Group::onlyTrashed()
        ->where('playlist_id', $this->playlist->id)
        ->where('deleted_at', '<', now()->subDays(30))
        ->forceDelete();

    // Old group should be permanently gone
    expect(Group::withTrashed()->where('id', $oldGroup->id)->exists())->toBeFalse();

    // Recent group should still be soft-deleted
    expect(Group::withTrashed()->where('id', $recentGroup->id)->exists())->toBeTrue();
});
