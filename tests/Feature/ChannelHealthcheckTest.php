<?php

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
    $this->actingAs($this->user, 'sanctum');
});

// ──────────────────────────────────────────────────────────────────────────────
// GET /channel/{id}/health — healthcheck (single channel, live probe)
// ──────────────────────────────────────────────────────────────────────────────

it('returns correct json structure for healthcheck', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'url' => 'http://invalid.example.test/stream.m3u8',
    ]);

    $response = $this->getJson("/channel/{$channel->id}/health");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => ['channel_id', 'title', 'name', 'url', 'stream_stats'],
        ])
        ->assertJsonPath('data.channel_id', $channel->id);
});

it('performs a live probe instead of returning stale stream_stats', function () {
    // Put stale stats in the database — if the controller still reads stream_stats
    // accessor, it would return these values. A live probe on a fake URL returns [].
    $staleStats = [['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920]]];

    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'url' => 'http://invalid.example.test/stream.m3u8',
        'stream_stats' => $staleStats,
    ]);

    $response = $this->getJson("/channel/{$channel->id}/health");

    // probeStreamStats() will fail on a fake URL and return []
    // If stream_stats accessor were used instead, we'd get back $staleStats
    $response->assertOk()
        ->assertJsonPath('data.stream_stats', []);
});

it('returns 404 when channel does not exist', function () {
    $this->getJson('/channel/999999/health')
        ->assertNotFound()
        ->assertJsonPath('success', false);
});

it('returns 403 when channel belongs to another user', function () {
    $otherUser = User::factory()->create();
    $otherPlaylist = Playlist::factory()->for($otherUser)->createQuietly();
    $channel = Channel::factory()->for($otherPlaylist)->create(['user_id' => $otherUser->id]);

    $this->getJson("/channel/{$channel->id}/health")
        ->assertForbidden()
        ->assertJsonPath('success', false);
});

// ──────────────────────────────────────────────────────────────────────────────
// GET /channel/playlist/{uuid}/health/{search} — healthcheckByPlaylist (live probe)
// ──────────────────────────────────────────────────────────────────────────────

it('returns correct structure and search metadata for playlist healthcheck', function () {
    Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'title' => 'ESPN HD',
        'url' => 'http://invalid.example.test/espn.m3u8',
    ]);

    $uuid = $this->playlist->uuid;

    $response = $this->getJson("/channel/playlist/{$uuid}/health/ESPN");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.search', 'ESPN')
        ->assertJsonPath('meta.playlist_uuid', $uuid)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonStructure([
            'data' => [['channel_id', 'title', 'name', 'url', 'stream_id', 'stream_stats']],
        ]);
});

it('performs live probes instead of returning stale stats for playlist healthcheck', function () {
    $staleStats = [['stream' => ['codec_type' => 'video', 'codec_name' => 'h264']]];

    Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'title' => 'LiveChannel',
        'url' => 'http://invalid.example.test/live.m3u8',
        'stream_stats' => $staleStats,
    ]);

    $uuid = $this->playlist->uuid;
    $response = $this->getJson("/channel/playlist/{$uuid}/health/LiveChannel");

    $response->assertOk();
    $data = $response->json('data');

    // probeStreamStats() returns [] on a fake URL — stale DB stats must not be returned
    expect($data)->toHaveCount(1)
        ->and($data[0]['stream_stats'])->toBe([]);
});

it('returns empty data when no channels match the search', function () {
    $uuid = $this->playlist->uuid;

    $this->getJson("/channel/playlist/{$uuid}/health/NOMATCH_XYZ_999")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.total', 0);
});

it('returns 404 when playlist does not exist', function () {
    $this->getJson('/channel/playlist/nonexistent-uuid/health/test')
        ->assertNotFound()
        ->assertJsonPath('success', false);
});

it('returns 403 when playlist belongs to another user', function () {
    $otherUser = User::factory()->create();
    $otherPlaylist = Playlist::factory()->for($otherUser)->createQuietly();

    $this->getJson("/channel/playlist/{$otherPlaylist->uuid}/health/test")
        ->assertForbidden()
        ->assertJsonPath('success', false);
});

it('searches across title name and stream_id fields', function () {
    Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'title' => 'MatchByTitleUnique',
    ]);
    Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'name' => 'MatchByNameUnique',
    ]);
    Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'stream_id' => 'match-by-streamid-unique',
    ]);

    $uuid = $this->playlist->uuid;

    $this->getJson("/channel/playlist/{$uuid}/health/MatchByTitleUnique")
        ->assertJsonPath('meta.total', 1);

    $this->getJson("/channel/playlist/{$uuid}/health/MatchByNameUnique")
        ->assertJsonPath('meta.total', 1);

    $this->getJson("/channel/playlist/{$uuid}/health/match-by-streamid-unique")
        ->assertJsonPath('meta.total', 1);
});
