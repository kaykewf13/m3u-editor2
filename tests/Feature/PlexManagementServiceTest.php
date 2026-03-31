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
    $devicesCallCount = 0;
    $dvrsCallCount = 0;

    Http::fake(function ($request) use (&$devicesCallCount, &$dvrsCallCount) {
        $url = $request->url();

        // Step 1: discover.json fetched via local app URL
        if (str_contains($url, '/hdhr/discover.json')) {
            return Http::response([
                'DeviceID' => 'hdhr-device-123',
                'DeviceAuth' => 'auth-abc',
                'FriendlyName' => 'm3u-editor HDHR',
                'ModelNumber' => 'HDHR5-4K',
            ]);
        }

        // Step 2: Create device in Plex (POST/PUT to /media/grabbers/devices with query params)
        if (str_contains($url, '/media/grabbers/devices') && str_contains($url, 'uri=')) {
            return Http::response([], 200);
        }

        // Step 3: GET /media/grabbers/devices (no query params)
        if (str_contains($url, '/media/grabbers/devices') && ! str_contains($url, '?')) {
            $devicesCallCount++;

            return Http::response([
                'MediaContainer' => [
                    'Device' => [
                        [
                            'key' => 'device-99',
                            'uuid' => 'device-uuid-abc',
                            'uri' => 'http://m3u-editor/hdhr',
                            'make' => 'Silicondust',
                            'model' => 'm3u-editor HDHR',
                        ],
                    ],
                ],
            ]);
        }

        // DVR create: POST /livetv/dvrs with device= query param
        if (str_contains($url, '/livetv/dvrs') && str_contains($url, 'device=') && $request->method() === 'POST') {
            return Http::response([
                'MediaContainer' => [
                    'Dvr' => [['key' => '42', 'uuid' => 'dvr-uuid-new']],
                ],
            ], 201);
        }

        // Attach device: PUT /livetv/dvrs/42/devices/device-99
        if (str_contains($url, '/livetv/dvrs/42/devices/device-99')) {
            return Http::response([], 200);
        }

        // GET /livetv/dvrs (sequence: first empty, then with DVR)
        if (str_contains($url, '/livetv/dvrs') && ! str_contains($url, '?') && ! str_contains($url, '/devices/')) {
            $dvrsCallCount++;

            if ($dvrsCallCount <= 2) {
                // First two calls: getDvrs() mapped call + raw fetch
                return Http::response(['MediaContainer' => ['Dvr' => []]]);
            }

            // After DVR creation: has DVR
            return Http::response(['MediaContainer' => ['Dvr' => [
                [
                    'key' => '42',
                    'uuid' => 'dvr-uuid-new',
                    'lineup' => 'lineup://tv.plex.providers.epg.xmltv/test-lineup',
                    'Device' => [
                        ['key' => 'device-99', 'uuid' => 'device-uuid-abc'],
                    ],
                ],
            ]]]);
        }

        // lineup.json for auto-sync after registration
        if (str_contains($url, '/hdhr/lineup.json')) {
            return Http::response([
                ['GuideNumber' => '1', 'GuideName' => 'Channel 1', 'URL' => 'http://example.com/1'],
            ]);
        }

        // lineupchannels for auto-sync
        if (str_contains($url, '/livetv/epg/lineupchannels')) {
            return Http::response(['MediaContainer' => ['Channel' => [
                ['number' => '1', 'lineupIdentifier' => 'ch-1'],
            ]]]);
        }

        // channelmap PUT for auto-sync
        if (str_contains($url, '/channelmap')) {
            return Http::response([], 200);
        }

        return Http::response([], 404);
    });

    $service = PlexManagementService::make($this->integration);
    $result = $service->addDvrDevice('http://m3u-editor/hdhr', 'http://m3u-editor/epg.xml', 'de', 'de', 'test-playlist-uuid');

    expect($result['success'])->toBeTrue();
    expect($result['dvr_id'])->toBe('42');

    $this->integration->refresh();
    expect($this->integration->plex_dvr_id)->toBe('42');
    $tuners = $this->integration->plex_dvr_tuners;
    expect($tuners)->toBeArray()->toHaveCount(1);
    expect($tuners[0]['device_key'])->toBe('device-99');
    expect($tuners[0]['playlist_uuid'])->toBe('test-playlist-uuid');
});

it('fails DVR registration when HDHR device is unreachable', function () {
    Http::fake([
        '*/hdhr/discover.json' => Http::response(null, 500),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->addDvrDevice('http://m3u-editor/hdhr', 'http://m3u-editor/epg.xml');

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('discover.json');
});

it('can remove a DVR', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [['device_key' => 'device-99', 'playlist_uuid' => 'test-uuid']],
    ]);

    Http::fake([
        'plex.example.com:32400/livetv/dvrs/42' => Http::response([], 200),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->removeDvr('42');

    expect($result['success'])->toBeTrue();
    $this->integration->refresh();
    expect($this->integration->plex_dvr_id)->toBeNull();
    expect($this->integration->plex_dvr_tuners)->toBeNull();
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
        'plex_dvr_tuners' => [
            ['device_key' => 'device-99', 'playlist_uuid' => 'playlist-uuid-abc'],
        ],
    ]);

    $this->integration->refresh();
    expect($this->integration->plex_dvr_id)->toBe('dvr-42');
    expect($this->integration->plex_machine_id)->toBe('machine-abc');
    expect($this->integration->plex_dvr_tuners)->toBeArray()->toHaveCount(1);
    expect($this->integration->plex_dvr_tuners[0]['device_key'])->toBe('device-99');
    expect($this->integration->plex_dvr_tuners[0]['playlist_uuid'])->toBe('playlist-uuid-abc');
});

it('can sync DVR channels when in sync', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [['device_key' => 'device-99', 'playlist_uuid' => 'test-uuid']],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/hdhr/lineup.json')) {
            return Http::response([
                ['GuideNumber' => '1', 'GuideName' => 'Ch 1', 'URL' => 'http://example.com/1'],
                ['GuideNumber' => '2', 'GuideName' => 'Ch 2', 'URL' => 'http://example.com/2'],
            ]);
        }

        if (str_contains($url, '/livetv/epg/lineupchannels')) {
            return Http::response(['MediaContainer' => ['Channel' => [
                ['number' => '1', 'lineupIdentifier' => 'ch-1'],
                ['number' => '2', 'lineupIdentifier' => 'ch-2'],
            ]]]);
        }

        if (str_contains($url, '/media/grabbers/devices') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Device' => [
                [
                    'key' => 'device-99',
                    'uri' => 'http://m3u-editor/hdhr',
                    'ChannelMapping' => [
                        ['lineupIdentifier' => 'ch-1', 'enabled' => '1'],
                        ['lineupIdentifier' => 'ch-2', 'enabled' => '1'],
                    ],
                ],
            ]]]);
        }

        if (str_contains($url, '/livetv/dvrs') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Dvr' => [
                ['key' => '42', 'lineup' => 'lineup://tv.plex.providers.epg.xmltv/test-lineup', 'Lineup' => [
                    ['id' => 'lineup://tv.plex.providers.epg.xmltv/test-lineup', 'title' => 'XMLTV Guide'],
                ], 'Device' => [['key' => 'device-99']]],
            ]]]);
        }

        return Http::response([], 200);
    });

    $service = PlexManagementService::make($this->integration);
    $result = $service->syncDvrChannels();

    expect($result['success'])->toBeTrue();
    expect($result['changed'])->toBeFalse();
    expect($result['mapped_channels'])->toBe(2);
});

it('can sync DVR channels when out of sync', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [['device_key' => 'device-99', 'playlist_uuid' => 'test-uuid']],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/hdhr/lineup.json')) {
            return Http::response([
                ['GuideNumber' => '1', 'GuideName' => 'Ch 1', 'URL' => 'http://example.com/1'],
                ['GuideNumber' => '2', 'GuideName' => 'Ch 2', 'URL' => 'http://example.com/2'],
                ['GuideNumber' => '3', 'GuideName' => 'Ch 3', 'URL' => 'http://example.com/3'],
            ]);
        }

        if (str_contains($url, '/livetv/epg/lineupchannels')) {
            return Http::response(['MediaContainer' => ['Channel' => [
                ['number' => '1', 'lineupIdentifier' => 'ch-1'],
                ['number' => '2', 'lineupIdentifier' => 'ch-2'],
                ['number' => '3', 'lineupIdentifier' => 'ch-3'],
            ]]]);
        }

        if (str_contains($url, '/media/grabbers/devices') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Device' => [
                [
                    'key' => 'device-99',
                    'uri' => 'http://m3u-editor/hdhr',
                    'ChannelMapping' => [
                        ['lineupIdentifier' => 'ch-1', 'enabled' => '1'],
                    ],
                ],
            ]]]);
        }

        if (str_contains($url, '/livetv/dvrs') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Dvr' => [
                ['key' => '42', 'lineup' => 'lineup://tv.plex.providers.epg.xmltv/test-lineup', 'Lineup' => [
                    ['id' => 'lineup://tv.plex.providers.epg.xmltv/test-lineup', 'title' => 'XMLTV Guide'],
                ], 'Device' => [['key' => 'device-99']]],
            ]]]);
        }

        if (str_contains($url, '/channelmap')) {
            return Http::response([], 200);
        }

        return Http::response([], 200);
    });

    $service = PlexManagementService::make($this->integration);
    $result = $service->syncDvrChannels();

    expect($result['success'])->toBeTrue();
    expect($result['changed'])->toBeTrue();
    expect($result['mapped_channels'])->toBe(3);
});

it('returns error when syncing without DVR configured', function () {
    $service = PlexManagementService::make($this->integration);
    $result = $service->syncDvrChannels();

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('not fully configured');
});

it('can refresh EPG guides', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
    ]);

    Http::fake([
        'plex.example.com:32400/livetv/dvrs/42' => Http::response(['MediaContainer' => ['Dvr' => [['Setting' => [
            ['id' => 'ButlerTaskRefreshEpgGuides', 'value' => 'true', 'default' => 'true'],
            ['id' => 'xmltvCustomRefreshInHours', 'value' => '24', 'default' => '24'],
        ]]]]], 200),
        'plex.example.com:32400/livetv/dvrs/42/prefs*' => Http::response([], 200),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->refreshGuides();

    expect($result['success'])->toBeTrue();
    expect($result['message'])->toContain('refresh');
});

it('can configure DVR preferences', function () {
    Http::fake([
        'plex.example.com:32400/livetv/dvrs/42' => Http::response(['MediaContainer' => ['Dvr' => [['Setting' => [
            ['id' => 'minVideoQuality', 'value' => '0', 'default' => '0'],
            ['id' => 'xmltvCustomRefreshInHours', 'value' => '24', 'default' => '24'],
            ['id' => 'ButlerTaskRefreshEpgGuides', 'value' => 'true', 'default' => 'true'],
        ]]]]], 200),
        'plex.example.com:32400/livetv/dvrs/42/prefs*' => Http::response([], 200),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->configureDvrPrefs('42', ['xmltvCustomRefreshInHours' => '6']);

    expect($result['success'])->toBeTrue();
    expect($result['message'])->toBe('DVR preferences updated');
});

it('can run sync plex dvr command', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [['device_key' => 'device-99', 'playlist_uuid' => 'test-uuid']],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/hdhr/lineup.json')) {
            return Http::response([]);
        }

        if (str_contains($url, '/livetv/epg/lineupchannels')) {
            return Http::response(['MediaContainer' => ['Channel' => []]]);
        }

        if (str_contains($url, '/media/grabbers/devices') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Device' => []]]);
        }

        if (str_contains($url, '/livetv/dvrs')) {
            return Http::response(['MediaContainer' => ['Dvr' => []]]);
        }

        return Http::response([], 200);
    });

    $this->artisan('app:sync-plex-dvr')
        ->assertExitCode(0);
});

it('resolves lineup ID from Lineup array entries', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [['device_key' => 'device-99', 'playlist_uuid' => 'test-uuid']],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/hdhr/lineup.json')) {
            return Http::response([
                ['GuideNumber' => '5', 'GuideName' => 'Ch 5', 'URL' => 'http://example.com/5'],
            ]);
        }

        if (str_contains($url, '/livetv/epg/lineupchannels')) {
            // Return a channel using the lineup ID from Lineup array
            return Http::response(['MediaContainer' => ['Channel' => [
                ['number' => '5', 'lineupIdentifier' => 'ch-5-from-lineup-array'],
            ]]]);
        }

        if (str_contains($url, '/media/grabbers/devices') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Device' => [
                [
                    'key' => 'device-99',
                    'ChannelMapping' => [
                        ['lineupIdentifier' => 'ch-5-from-lineup-array', 'enabled' => '1'],
                    ],
                ],
            ]]]);
        }

        if (str_contains($url, '/livetv/dvrs') && ! str_contains($url, '?')) {
            // DVR with Lineup array (not just top-level lineup field)
            return Http::response(['MediaContainer' => ['Dvr' => [
                [
                    'key' => '42',
                    'lineup' => 'lineup://wrong-top-level',
                    'Lineup' => [
                        ['id' => 'lineup://tv.plex.providers.epg.xmltv/correct-lineup', 'title' => 'XMLTV Guide from array'],
                    ],
                    'Device' => [['key' => 'device-99']],
                ],
            ]]]);
        }

        return Http::response([], 200);
    });

    $service = PlexManagementService::make($this->integration);
    $result = $service->syncDvrChannels();

    expect($result['success'])->toBeTrue();
    expect($result['mapped_channels'])->toBe(1);
});

it('triggers EPG refresh after channel map changes', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [['device_key' => 'device-99', 'playlist_uuid' => 'test-uuid']],
    ]);

    $butlerCalled = false;
    Http::fake(function ($request) use (&$butlerCalled) {
        $url = $request->url();

        if (str_contains($url, '/butler/RefreshEPGGuides')) {
            $butlerCalled = true;

            return Http::response([], 200);
        }

        if (str_contains($url, '/hdhr/lineup.json')) {
            return Http::response([
                ['GuideNumber' => '1', 'GuideName' => 'Ch 1', 'URL' => 'http://example.com/1'],
            ]);
        }

        if (str_contains($url, '/livetv/epg/lineupchannels')) {
            return Http::response(['MediaContainer' => ['Channel' => []]]);
        }

        if (str_contains($url, '/media/grabbers/devices') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Device' => [
                ['key' => 'device-99', 'ChannelMapping' => []],
            ]]]);
        }

        if (str_contains($url, '/livetv/dvrs') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Dvr' => [
                ['key' => '42', 'lineup' => 'lineup://test', 'Device' => [['key' => 'device-99']]],
            ]]]);
        }

        if (str_contains($url, '/channelmap')) {
            return Http::response([], 200);
        }

        return Http::response([], 200);
    });

    $service = PlexManagementService::make($this->integration);
    $result = $service->syncDvrChannels();

    expect($result['success'])->toBeTrue();
    expect($result['changed'])->toBeTrue();
    expect($butlerCalled)->toBeTrue();
    expect($result['message'])->toContain('EPG refresh triggered');
});

it('handles single channel response from Plex lineup', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [['device_key' => 'device-99', 'playlist_uuid' => 'test-uuid']],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/hdhr/lineup.json')) {
            return Http::response([
                ['GuideNumber' => '10', 'GuideName' => 'Ch 10', 'URL' => 'http://example.com/10'],
            ]);
        }

        if (str_contains($url, '/livetv/epg/lineupchannels')) {
            // Single channel returned as object (not array) — Plex does this for single results
            return Http::response(['MediaContainer' => ['Channel' => [
                'number' => '10', 'lineupIdentifier' => 'single-ch-10',
            ]]]);
        }

        if (str_contains($url, '/media/grabbers/devices') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Device' => [
                ['key' => 'device-99', 'ChannelMapping' => []],
            ]]]);
        }

        if (str_contains($url, '/livetv/dvrs') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Dvr' => [
                ['key' => '42', 'lineup' => 'lineup://test', 'Device' => [['key' => 'device-99']]],
            ]]]);
        }

        if (str_contains($url, '/channelmap')) {
            return Http::response([], 200);
        }

        return Http::response([], 200);
    });

    $service = PlexManagementService::make($this->integration);
    $result = $service->syncDvrChannels();

    expect($result['success'])->toBeTrue();
    expect($result['mapped_channels'])->toBe(1);
});
