<?php

use App\Models\Network;
use App\Models\NetworkProgramme;
use App\Services\NetworkBroadcastService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        '*/broadcast/*/status' => Http::response(['status' => 'stopped'], 404),
        '*/broadcast/*/start' => Http::response([
            'status' => 'running',
            'ffmpeg_pid' => 12345,
        ], 200),
        '*' => Http::response([], 200),
    ]);
});

it('waits for connection in on-demand mode during worker tick', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_on_demand' => true,
        'broadcast_last_connection_at' => null,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
    ]);

    $service = app(NetworkBroadcastService::class);
    $result = $service->tick($network);

    expect($result['action'])->toBe('waiting_for_connection');
    expect($network->fresh()->broadcast_pid)->toBeNull();
});

it('manual start bypasses on-demand waiting and starts broadcast', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_on_demand' => true,
        'broadcast_last_connection_at' => null,
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class, function ($mock) use ($network) {
        $mock->makePartial();
        $mock->shouldAllowMockingProtectedMethods();
        $mock->shouldReceive('startInternal')->once()->with($network, true)->andReturn(true);
    });

    $result = $service->startNow($network);

    expect($result)->toBeTrue();
});

it('marks waiting state on model when requested and on-demand with no connection', function () {
    $network = Network::factory()->create([
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_on_demand' => true,
        'broadcast_started_at' => null,
        'broadcast_pid' => null,
        'broadcast_last_connection_at' => null,
    ]);

    expect($network->isWaitingForConnection())->toBeTrue();
});

it('stops running on-demand broadcast after disconnect window but keeps request state', function () {
    config()->set('proxy.broadcast_on_demand_disconnect_seconds', 120);
    config()->set('proxy.broadcast_on_demand_overlap_seconds', 30);

    Http::fake([
        '*/broadcast/*/stop' => Http::response(['status' => 'stopped', 'final_segment_number' => 0], 200),
        '*/broadcast/*' => Http::response([], 200),
    ]);

    Carbon::setTestNow(now());

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_on_demand' => true,
        'broadcast_started_at' => now()->subMinutes(10),
        'broadcast_pid' => 12345,
        'broadcast_last_connection_at' => now()->subMinutes(4),
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(20),
        'end_time' => now()->addMinutes(20),
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class, function ($mock): void {
        $mock->makePartial();
        $mock->shouldAllowMockingProtectedMethods();
        $mock->shouldReceive('isProcessRunning')->andReturn(true);
        $mock->shouldReceive('stop')
            ->once()
            ->withArgs(fn (Network $n, bool $keepRequested = false, bool $preservePlaybackReference = false): bool => $keepRequested && $preservePlaybackReference
            )
            ->andReturnUsing(function (Network $n, bool $keepRequested = false, bool $preservePlaybackReference = false): bool {
                return (new NetworkBroadcastService)->stop($n, $keepRequested, $preservePlaybackReference);
            });
    });

    $result = $service->tick($network);

    expect($result['action'])->toBe('stopped_waiting_for_connection');
    expect($network->fresh()->broadcast_requested)->toBeTrue();
    expect($network->fresh()->broadcast_pid)->toBeNull();

    Carbon::setTestNow();
});

it('preserves playback timeline across on-demand idle stop and reconnect start', function () {
    Carbon::setTestNow(now());

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_on_demand' => true,
        'broadcast_started_at' => now()->subMinutes(5),
        'broadcast_initial_offset_seconds' => 300,
        'broadcast_pid' => 43210,
        'broadcast_last_connection_at' => now()->subMinutes(2),
    ]);

    $programme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(20),
        'end_time' => now()->addMinutes(20),
    ]);

    $network->update([
        'broadcast_programme_id' => $programme->id,
    ]);

    $service = app(NetworkBroadcastService::class);
    $service->stop($network, keepRequested: true, preservePlaybackReference: true);

    $network->refresh();

    expect($network->broadcast_requested)->toBeTrue();
    expect($network->broadcast_programme_id)->toBe($programme->id);
    expect($network->broadcast_initial_offset_seconds)->toBe(600);

    Carbon::setTestNow(now()->addSeconds(30));

    expect($network->fresh()->getPersistedBroadcastSeekForNow())->toBe(630);

    Carbon::setTestNow();
});
