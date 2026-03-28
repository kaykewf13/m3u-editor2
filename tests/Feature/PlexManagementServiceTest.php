<?php

use App\Models\MediaServerIntegration;
use App\Models\User;
use App\Services\PlexManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Bus::fake();
    $this->user = User::factory()->create();
    $this->integration = MediaServerIntegration::withoutEvents(function () {
        return MediaServerIntegration::create([
            'name' => 'Test Plex Server',
            'type' => 'plex',
            'host' => 'plex.example.com',
            'port' => 32400,
            'ssl' => false,
            'api_key' => 'test-plex-token',
            'enabled' => true,
            'user_id' => $this->user->id,
            'plex_management_enabled' => true,
        ]);
    });
});

it('throws exception for non-plex integration', function () {
    $embyIntegration = MediaServerIntegration::withoutEvents(function () {
        return MediaServerIntegration::create([
            'name' => 'Emby Server',
            'type' => 'emby',
            'host' => '192.168.1.100',
            'api_key' => 'test-key',
            'user_id' => $this->user->id,
        ]);
    });

    PlexManagementService::make($embyIntegration);
})->throws(InvalidArgumentException::class, 'PlexManagementService requires a Plex integration');

it('can get server info', function () {
    Http::fake([
        'plex.example.com:32400/' => Http::response([
            'MediaContainer' => [
                'friendlyName' => 'My Plex Server',
                'version' => '1.40.0',
                'platform' => 'Linux',
                'machineIdentifier' => 'abc123',
            ],
        ]),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->getServerInfo();

    expect($result['success'])->toBeTrue();
    expect($result['data']['name'])->toBe('My Plex Server');
    expect($result['data']['version'])->toBe('1.40.0');
    expect($result['data']['platform'])->toBe('Linux');
    expect($result['data']['machine_id'])->toBe('abc123');
});

it('handles server info failure gracefully', function () {
    Http::fake([
        'plex.example.com:32400/*' => Http::response(null, 500),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->getServerInfo();

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('500');
});

it('can get active sessions', function () {
    Http::fake([
        'plex.example.com:32400/status/sessions' => Http::response([
            'MediaContainer' => [
                'Metadata' => [
                    [
                        'sessionKey' => '1',
                        'title' => 'Test Movie',
                        'type' => 'movie',
                        'User' => ['title' => 'TestUser'],
                        'Player' => ['title' => 'Chrome', 'state' => 'playing'],
                        'viewOffset' => 60000,
                        'duration' => 7200000,
                    ],
                ],
            ],
        ]),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->getActiveSessions();

    expect($result['success'])->toBeTrue();
    expect($result['data'])->toHaveCount(1);
    expect($result['data']->first()['title'])->toBe('Test Movie');
    expect($result['data']->first()['user'])->toBe('TestUser');
    expect($result['data']->first()['state'])->toBe('playing');
});

it('can get DVR configurations', function () {
    Http::fake([
        'plex.example.com:32400/livetv/dvrs' => Http::response([
            'MediaContainer' => [
                'Dvr' => [
                    [
                        'key' => '1',
                        'uuid' => 'dvr-uuid-123',
                        'title' => 'My DVR',
                        'lineup' => 'http://example.com/epg.xml',
                        'language' => 'en',
                        'country' => 'US',
                        'Device' => [
                            [
                                'key' => 'device-1',
                                'uri' => 'http://192.168.1.100:8080',
                                'make' => 'Silicondust',
                                'model' => 'HDHR5-4K',
                                'tuners' => 4,
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->getDvrs();

    expect($result['success'])->toBeTrue();
    expect($result['data'])->toHaveCount(1);
    expect($result['data']->first()['id'])->toBe('1');
    expect($result['data']->first()['device_count'])->toBe(1);
});

it('can register a DVR device', function () {
    Http::fake([
        'plex.example.com:32400/livetv/dvrs' => Http::response([
            'MediaContainer' => [
                'Dvr' => [
                    [
                        'key' => '42',
                        'uuid' => 'new-dvr-uuid',
                    ],
                ],
            ],
        ]),
        'plex.example.com:32400/livetv/dvrs/42' => Http::response([], 200),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->addDvrDevice('http://m3u-editor/hdhr', 'http://m3u-editor/epg.xml');

    expect($result['success'])->toBeTrue();
    expect($result['dvr_id'])->toBe('42');

    $this->integration->refresh();
    expect($this->integration->plex_dvr_id)->toBe('42');
});

it('can remove a DVR', function () {
    $this->integration->update(['plex_dvr_id' => '42']);

    Http::fake([
        'plex.example.com:32400/livetv/dvrs/42' => Http::response([], 200),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->removeDvr('42');

    expect($result['success'])->toBeTrue();
    $this->integration->refresh();
    expect($this->integration->plex_dvr_id)->toBeNull();
});

it('can get all libraries', function () {
    Http::fake([
        'plex.example.com:32400/library/sections' => Http::response([
            'MediaContainer' => [
                'Directory' => [
                    [
                        'key' => '1',
                        'title' => 'Movies',
                        'type' => 'movie',
                        'agent' => 'tv.plex.agents.movie',
                        'Location' => [['path' => '/media/movies']],
                    ],
                    [
                        'key' => '2',
                        'title' => 'TV Shows',
                        'type' => 'show',
                        'agent' => 'tv.plex.agents.series',
                        'Location' => [['path' => '/media/tv']],
                    ],
                ],
            ],
        ]),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->getAllLibraries();

    expect($result['success'])->toBeTrue();
    expect($result['data'])->toHaveCount(2);
    expect($result['data']->first()['title'])->toBe('Movies');
    expect($result['data']->last()['title'])->toBe('TV Shows');
});

it('can scan a library', function () {
    Http::fake([
        'plex.example.com:32400/library/sections/1/refresh' => Http::response([], 200),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->scanLibrary('1');

    expect($result['success'])->toBeTrue();
});

it('can get recordings', function () {
    Http::fake([
        'plex.example.com:32400/media/subscriptions' => Http::response([
            'MediaContainer' => [
                'MediaSubscription' => [
                    [
                        'key' => 'rec-1',
                        'title' => 'Evening News',
                        'type' => 'recording',
                        'createdAt' => 1711612800,
                    ],
                ],
            ],
        ]),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->getRecordings();

    expect($result['success'])->toBeTrue();
    expect($result['data'])->toHaveCount(1);
    expect($result['data']->first()['title'])->toBe('Evening News');
});

it('can cancel a recording', function () {
    Http::fake([
        'plex.example.com:32400/media/subscriptions/rec-1' => Http::response([], 200),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->cancelRecording('rec-1');

    expect($result['success'])->toBeTrue();
});

it('can terminate a session', function () {
    Http::fake([
        'plex.example.com:32400/status/sessions/terminate/player*' => Http::response([], 200),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->terminateSession('session-1');

    expect($result['success'])->toBeTrue();
});

it('can get dashboard summary', function () {
    Http::fake([
        'plex.example.com:32400/' => Http::response([
            'MediaContainer' => [
                'friendlyName' => 'My Plex',
                'version' => '1.40.0',
                'platform' => 'Linux',
                'machineIdentifier' => 'abc',
            ],
        ]),
        'plex.example.com:32400/status/sessions' => Http::response([
            'MediaContainer' => ['Metadata' => []],
        ]),
        'plex.example.com:32400/livetv/dvrs' => Http::response([
            'MediaContainer' => ['Dvr' => []],
        ]),
        'plex.example.com:32400/library/sections' => Http::response([
            'MediaContainer' => ['Directory' => []],
        ]),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->getDashboardSummary();

    expect($result['success'])->toBeTrue();
    expect($result['data']['server']['name'])->toBe('My Plex');
    expect($result['data']['active_sessions'])->toBe(0);
    expect($result['data']['dvr_count'])->toBe(0);
    expect($result['data']['library_count'])->toBe(0);
});

it('stores plex management fields on model', function () {
    expect($this->integration->plex_management_enabled)->toBeTrue();
    expect($this->integration->plex_dvr_id)->toBeNull();
    expect($this->integration->plex_machine_id)->toBeNull();

    $this->integration->update([
        'plex_dvr_id' => 'dvr-42',
        'plex_machine_id' => 'machine-abc',
    ]);

    $this->integration->refresh();
    expect($this->integration->plex_dvr_id)->toBe('dvr-42');
    expect($this->integration->plex_machine_id)->toBe('machine-abc');
});
