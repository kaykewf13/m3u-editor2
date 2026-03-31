<?php

namespace App\Services;

use App\Models\MediaServerIntegration;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlexManagementService
{
    protected MediaServerIntegration $integration;

    protected string $baseUrl;

    protected string $apiKey;

    public function __construct(MediaServerIntegration $integration)
    {
        if (! $integration->isPlex()) {
            throw new \InvalidArgumentException('PlexManagementService requires a Plex integration');
        }

        $this->integration = $integration;
        $this->baseUrl = $integration->base_url;
        $this->apiKey = $integration->api_key;
    }

    public static function make(MediaServerIntegration $integration): self
    {
        return new self($integration);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout(30)
            ->withHeaders([
                'X-Plex-Token' => $this->apiKey,
                'Accept' => 'application/json',
                'X-Plex-Client-Identifier' => 'm3u-editor',
                'X-Plex-Product' => 'm3u-editor',
            ]);
    }

    /**
     * Get Plex server information.
     *
     * @return array{success: bool, data?: array{name: string, version: string, platform: string, machine_id: string, transcoder: bool}, message?: string}
     */
    public function getServerInfo(): array
    {
        try {
            $response = $this->client()->get('/');

            if (! $response->successful()) {
                return ['success' => false, 'message' => 'Server returned status: '.$response->status()];
            }

            $container = $response->json('MediaContainer', []);

            return [
                'success' => true,
                'data' => [
                    'name' => $container['friendlyName'] ?? 'Unknown',
                    'version' => $container['version'] ?? 'Unknown',
                    'platform' => $container['platform'] ?? 'Unknown',
                    'machine_id' => $container['machineIdentifier'] ?? '',
                    'transcoder' => (bool) ($container['transcoderActiveVideoSessions'] ?? false),
                ],
            ];
        } catch (Exception $e) {
            Log::warning('PlexManagementService: Failed to get server info', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get active sessions (currently streaming).
     *
     * @return array{success: bool, data?: Collection, message?: string}
     */
    public function getActiveSessions(): array
    {
        try {
            $response = $this->client()->get('/status/sessions');

            if (! $response->successful()) {
                return ['success' => false, 'message' => 'Failed to fetch sessions: '.$response->status()];
            }

            $sessions = collect($response->json('MediaContainer.Metadata', []))
                ->map(fn (array $session) => [
                    'id' => $session['sessionKey'] ?? null,
                    'title' => $session['title'] ?? 'Unknown',
                    'type' => $session['type'] ?? 'unknown',
                    'user' => $session['User']['title'] ?? 'Unknown',
                    'player' => $session['Player']['title'] ?? 'Unknown',
                    'state' => $session['Player']['state'] ?? 'unknown',
                    'progress' => $session['viewOffset'] ?? 0,
                    'duration' => $session['duration'] ?? 0,
                    'transcode' => ! empty($session['TranscodeSession']),
                ]);

            return ['success' => true, 'data' => $sessions];
        } catch (Exception $e) {
            Log::warning('PlexManagementService: Failed to get sessions', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Terminate an active Plex session.
     */
    public function terminateSession(string $sessionId, string $reason = 'Session terminated by m3u-editor'): array
    {
        try {
            $response = $this->client()->get('/status/sessions/terminate/player', [
                'sessionId' => $sessionId,
                'reason' => $reason,
            ]);

            return [
                'success' => $response->successful(),
                'message' => $response->successful() ? 'Session terminated' : 'Failed: '.$response->status(),
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get all DVR configurations from Plex.
     *
     * @return array{success: bool, data?: Collection, message?: string}
     */
    public function getDvrs(): array
    {
        try {
            $response = $this->client()->get('/livetv/dvrs');

            if (! $response->successful()) {
                return ['success' => false, 'message' => 'Failed to fetch DVRs: '.$response->status()];
            }

            $dvrs = collect($response->json('MediaContainer.Dvr', []))
                ->map(fn (array $dvr) => [
                    'id' => $dvr['key'] ?? null,
                    'uuid' => $dvr['uuid'] ?? null,
                    'title' => $dvr['title'] ?? 'DVR',
                    'lineup' => $dvr['lineup'] ?? null,
                    'language' => $dvr['language'] ?? null,
                    'country' => $dvr['country'] ?? null,
                    'device_count' => count($dvr['Device'] ?? []),
                    'devices' => collect($dvr['Device'] ?? [])->map(fn (array $device) => [
                        'key' => $device['key'] ?? null,
                        'uri' => $device['uri'] ?? null,
                        'make' => $device['make'] ?? 'Unknown',
                        'model' => $device['model'] ?? 'Unknown',
                        'source' => $device['source'] ?? null,
                        'tuners' => $device['tuners'] ?? 0,
                    ])->toArray(),
                ]);

            return ['success' => true, 'data' => $dvrs];
        } catch (Exception $e) {
            Log::warning('PlexManagementService: Failed to get DVRs', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get all grabber devices registered in Plex.
     *
     * @return array{success: bool, data?: array, message?: string}
     */
    public function getDevices(): array
    {
        try {
            $response = $this->client()->get('/media/grabbers/devices');

            if (! $response->successful()) {
                return ['success' => false, 'message' => 'Failed to fetch devices: '.$response->status()];
            }

            return ['success' => true, 'data' => $response->json('MediaContainer.Device', [])];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Try to create a grabber device in Plex using multiple API variations.
     *
     * Plex accepts different methods/params depending on version. We try all known
     * variations (mirroring Headendarr's try_create_device approach).
     *
     * @param  array{DeviceID?: string, DeviceAuth?: string}  $discoverPayload
     */
    protected function tryCreateDevice(string $hdhrBaseUrl, array $discoverPayload = []): bool
    {
        $candidates = [
            ['POST', '/media/grabbers/devices', ['uri' => $hdhrBaseUrl]],
            ['PUT', '/media/grabbers/devices', ['uri' => $hdhrBaseUrl]],
            ['POST', '/media/grabbers/devices', ['url' => $hdhrBaseUrl]],
            ['POST', '/media/grabbers/devices', [
                'uri' => $hdhrBaseUrl,
                'deviceId' => $discoverPayload['DeviceID'] ?? '',
                'deviceAuth' => $discoverPayload['DeviceAuth'] ?? '',
            ]],
        ];

        foreach ($candidates as [$method, $path, $query]) {
            try {
                $url = $path.'?'.http_build_query($query);
                $response = match ($method) {
                    'PUT' => $this->client()->withBody('')->put($url),
                    default => $this->client()->withBody('')->post($url),
                };

                if ($response->successful()) {
                    return true;
                }

                Log::debug('PlexManagementService: Device create attempt failed', [
                    'method' => $method,
                    'path' => $path,
                    'query' => $query,
                    'status' => $response->status(),
                ]);
            } catch (Exception $e) {
                Log::debug('PlexManagementService: Device create attempt exception', [
                    'method' => $method,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return false;
    }

    /**
     * Find a device in the device list matching the given URI.
     *
     * @param  array  $devices  List of Plex device arrays
     */
    protected function findDeviceByUri(array $devices, string $expectedUri): ?array
    {
        foreach ($devices as $device) {
            if (! is_array($device)) {
                continue;
            }
            $uri = trim($device['uri'] ?? '');
            if ($uri === $expectedUri) {
                return $device;
            }
        }

        return null;
    }

    /**
     * Collect all devices from both /media/grabbers/devices and DVR device lists.
     *
     * @param  array  $grabberDevices  Devices from GET /media/grabbers/devices
     * @param  array  $dvrs  DVRs from GET /livetv/dvrs
     */
    protected function flattenAllDevices(array $grabberDevices, array $dvrs): array
    {
        $devices = [];
        $seen = [];

        foreach ($grabberDevices as $device) {
            if (! is_array($device)) {
                continue;
            }
            $key = $device['key'] ?? '';
            if ($key && ! isset($seen[$key])) {
                $devices[] = $device;
                $seen[$key] = true;
            }
        }

        foreach ($dvrs as $dvr) {
            if (! is_array($dvr)) {
                continue;
            }
            $dvrKey = $dvr['key'] ?? '';
            foreach ($dvr['Device'] ?? [] as $device) {
                if (! is_array($device)) {
                    continue;
                }
                $key = $device['key'] ?? '';
                if ($key && ! isset($seen[$key])) {
                    if (! isset($device['parentID']) && $dvrKey) {
                        $device['parentID'] = $dvrKey;
                    }
                    $devices[] = $device;
                    $seen[$key] = true;
                }
            }
        }

        return $devices;
    }

    /**
     * Build a Plex XMLTV lineup ID from an EPG URL and guide title.
     */
    protected function buildLineupId(string $epgUrl, string $guideTitle = 'm3u-editor XMLTV Guide'): string
    {
        return 'lineup://tv.plex.providers.epg.xmltv/'.rawurlencode($epgUrl).'#'.rawurlencode($guideTitle);
    }

    /**
     * Register an m3u-editor playlist's HDHR endpoint as a DVR tuner in Plex.
     *
     * Follows the correct Plex DVR API flow (as implemented by Headendarr):
     * 1. Fetch discover.json from the HDHR endpoint to get device info
     * 2. Create the device in Plex via POST /media/grabbers/devices
     * 3. Find the created device by URI
     * 4. Create a DVR entry linking device + XMLTV lineup
     * 5. Attach device to DVR and store DVR ID
     *
     * @param  string  $hdhrBaseUrl  The HDHR base URL reachable by Plex (e.g., http://192.168.1.x:36400/{uuid}/hdhr)
     * @param  string  $epgUrl  The EPG XML URL reachable by Plex
     * @param  string  $country  Country code for DVR (default: de)
     * @param  string  $language  Language code for DVR (default: de)
     */
    public function addDvrDevice(string $hdhrBaseUrl, string $epgUrl, string $country = 'de', string $language = 'de', ?string $playlistUuid = null): array
    {
        try {
            // Step 1: Fetch discover.json to get device info (DeviceID, DeviceAuth, etc.)
            // The hdhrBaseUrl is the external URL for Plex. We build a local URL
            // to fetch discover.json from ourselves since we host the HDHR endpoint.
            $parsedHdhr = parse_url($hdhrBaseUrl);
            $hdhrPath = $parsedHdhr['path'] ?? '';
            $appPort = config('app.port', 36400);
            $localDiscoverUrl = "http://localhost:{$appPort}".rtrim($hdhrPath, '/').'/discover.json';

            $discoverResponse = Http::timeout(10)->get($localDiscoverUrl);
            if (! $discoverResponse->successful()) {
                return [
                    'success' => false,
                    'message' => "Could not reach HDHR discover.json (HTTP {$discoverResponse->status()}). Ensure the HDHR endpoint is available.",
                ];
            }
            $discoverPayload = $discoverResponse->json() ?? [];

            // Step 2: Create the device in Plex
            $created = $this->tryCreateDevice($hdhrBaseUrl, $discoverPayload);
            if (! $created) {
                Log::warning('PlexManagementService: All device creation attempts failed', [
                    'integration_id' => $this->integration->id,
                    'hdhr_url' => $hdhrBaseUrl,
                ]);

                return [
                    'success' => false,
                    'message' => 'Plex rejected the device creation. Ensure the HDHR URL is reachable from Plex and returns valid discover.json/lineup.json.',
                ];
            }

            // Step 3: Find the created device by URI
            $devicesResult = $this->getDevices();
            $dvrsResult = $this->getDvrs();

            $grabberDevices = $devicesResult['success'] ? ($devicesResult['data'] ?? []) : [];
            $dvrList = $dvrsResult['success'] ? ($dvrsResult['data'] ?? []) : [];

            // For flattenAllDevices we need raw DVR data, re-fetch
            $rawDvrs = [];
            if ($dvrsResult['success']) {
                $rawDvrsResponse = $this->client()->get('/livetv/dvrs');
                $rawDvrs = $rawDvrsResponse->json('MediaContainer.Dvr', []);
            }

            $allDevices = $this->flattenAllDevices($grabberDevices, $rawDvrs);
            $targetDevice = $this->findDeviceByUri($allDevices, $hdhrBaseUrl);

            if (! $targetDevice) {
                return [
                    'success' => false,
                    'message' => 'Device was created but could not be found in Plex. Try again or check Plex logs.',
                ];
            }

            $deviceKey = $targetDevice['key'] ?? null;
            $deviceUuid = $targetDevice['uuid'] ?? null;

            if (! $deviceKey || ! $deviceUuid) {
                return [
                    'success' => false,
                    'message' => 'Device found but missing key/uuid. Plex may need a restart.',
                ];
            }

            // Step 4: Check for existing DVR or create a new one
            $dvrKey = $targetDevice['parentID'] ?? null;

            if (! $dvrKey) {
                // No existing DVR — create one
                $lineupId = $this->buildLineupId($epgUrl);
                $createDvrResponse = $this->client()->withBody('')->post('/livetv/dvrs?'.http_build_query([
                    'device' => $deviceUuid,
                    'lineup' => $lineupId,
                    'lineupTitle' => 'm3u-editor XMLTV Guide',
                    'country' => $country,
                    'language' => $language,
                ]));

                if (! $createDvrResponse->successful()) {
                    Log::warning('PlexManagementService: DVR creation failed', [
                        'integration_id' => $this->integration->id,
                        'status' => $createDvrResponse->status(),
                        'response' => $createDvrResponse->body(),
                    ]);

                    return [
                        'success' => false,
                        'message' => 'Device registered but DVR creation failed (HTTP '.$createDvrResponse->status().').',
                    ];
                }

                // Re-fetch DVRs to find the new DVR key
                $dvrsRefresh = $this->client()->get('/livetv/dvrs');
                $freshDvrs = $dvrsRefresh->json('MediaContainer.Dvr', []);
                foreach ($freshDvrs as $dvr) {
                    foreach ($dvr['Device'] ?? [] as $dvrDevice) {
                        if (($dvrDevice['uuid'] ?? '') === $deviceUuid || ($dvrDevice['key'] ?? '') === $deviceKey) {
                            $dvrKey = $dvr['key'] ?? null;
                            break 2;
                        }
                    }
                }

                // If still no DVR key, use the first DVR
                if (! $dvrKey && ! empty($freshDvrs)) {
                    $dvrKey = $freshDvrs[0]['key'] ?? null;
                }
            }

            // Step 5: Attach device to DVR (if not already attached)
            if ($dvrKey && $deviceKey && ! ($targetDevice['parentID'] ?? null)) {
                $this->client()->withBody('')->put("/livetv/dvrs/{$dvrKey}/devices/{$deviceKey}");
            }

            // Store the DVR ID and add tuner to the tuners array
            if ($dvrKey) {
                $tuners = $this->integration->plex_dvr_tuners ?? [];
                if ($playlistUuid && $deviceKey) {
                    $tuners[] = [
                        'device_key' => $deviceKey,
                        'playlist_uuid' => $playlistUuid,
                    ];
                }
                $this->integration->update([
                    'plex_dvr_id' => $dvrKey,
                    'plex_dvr_tuners' => $tuners,
                ]);
            }

            // Sync channel map immediately after registration
            $syncResult = $this->syncDvrChannelsForTuner($deviceKey, $playlistUuid);
            $channelInfo = $syncResult['success'] ? " ({$syncResult['mapped_channels']} channels mapped)" : '';

            return [
                'success' => true,
                'message' => 'HDHR device registered and DVR configured in Plex'.($dvrKey ? " (DVR ID: {$dvrKey})" : '').$channelInfo,
                'dvr_id' => $dvrKey,
            ];
        } catch (Exception $e) {
            Log::error('PlexManagementService: Failed to add DVR device', [
                'integration_id' => $this->integration->id,
                'hdhr_url' => $hdhrBaseUrl,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Configure DVR preferences (EPG refresh settings, recording settings).
     *
     * Plex requires ALL settings to be sent in a single PUT request.
     * Fetches current settings first, merges overrides, then sends them all.
     *
     * @param  array<string, string>  $settings
     */
    public function configureDvrPrefs(string $dvrId, array $settings = []): array
    {
        try {
            $currentSettings = $this->fetchCurrentDvrSettings($dvrId);

            $prefs = array_merge($currentSettings, $settings);

            $response = $this->client()
                ->withBody('')
                ->put("/livetv/dvrs/{$dvrId}/prefs?".http_build_query($prefs));

            Log::debug('PlexManagementService: configureDvrPrefs response', [
                'dvr_id' => $dvrId,
                'status' => $response->status(),
                'prefs' => array_keys($prefs),
            ]);

            return [
                'success' => $response->successful(),
                'message' => $response->successful()
                    ? 'DVR preferences updated'
                    : 'Failed to update DVR preferences: HTTP '.$response->status(),
            ];
        } catch (Exception $e) {
            Log::error('PlexManagementService: configureDvrPrefs exception', [
                'dvr_id' => $dvrId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Fetch current DVR settings from Plex and return as id => value map.
     *
     * @return array<string, string>
     */
    protected function fetchCurrentDvrSettings(string $dvrId): array
    {
        $response = $this->client()->get("/livetv/dvrs/{$dvrId}");

        if (! $response->successful()) {
            return $this->defaultDvrSettings();
        }

        $dvr = $response->json('MediaContainer.Dvr.0', []);
        $settings = [];

        foreach ($dvr['Setting'] ?? [] as $setting) {
            $settings[$setting['id']] = $setting['value'] ?? $setting['default'] ?? '';
        }

        return $settings ?: $this->defaultDvrSettings();
    }

    /**
     * Default DVR settings matching Plex defaults.
     *
     * @return array<string, string>
     */
    protected function defaultDvrSettings(): array
    {
        return [
            'minVideoQuality' => '0',
            'replaceLowerQuality' => 'false',
            'recordPartials' => 'true',
            'startOffsetMinutes' => '0',
            'endOffsetMinutes' => '0',
            'useUmp' => 'false',
            'postprocessingScript' => '',
            'comskipMethod' => '0',
            'ButlerTaskRefreshEpgGuides' => 'true',
            'mediaProviderEpgXmltvGuideRefreshStartTime' => '10',
            'xmltvCustomRefreshInHours' => '24',
            'kidsCategories' => 'kids',
            'newsCategories' => 'news',
            'sportsCategories' => 'sports',
        ];
    }

    /**
     * Ensure EPG guide auto-refresh is enabled in DVR preferences.
     *
     * Plex manages EPG refresh internally via the butler scheduler.
     * There is no manual trigger endpoint — we configure the DVR prefs
     * to enable the refresh task and set the desired interval.
     */
    public function refreshGuides(): array
    {
        try {
            $dvrId = $this->integration->plex_dvr_id;
            if (! $dvrId) {
                return ['success' => false, 'message' => 'No DVR registered. Register a tuner first.'];
            }

            $prefsResult = $this->configureDvrPrefs($dvrId, [
                'ButlerTaskRefreshEpgGuides' => 'true',
                'xmltvCustomRefreshInHours' => '12',
            ]);

            Log::debug('PlexManagementService: refreshGuides result', [
                'dvr_id' => $dvrId,
                'success' => $prefsResult['success'],
            ]);

            if ($prefsResult['success']) {
                return ['success' => true, 'message' => 'EPG auto-refresh enabled (every 12 hours). Plex will re-fetch the guide data on its next scheduled run.'];
            }

            return $prefsResult;
        } catch (Exception $e) {
            Log::error('PlexManagementService: refreshGuides exception', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Remove a DVR configuration from Plex.
     */
    public function removeDvr(string $dvrId): array
    {
        try {
            $response = $this->client()->delete("/livetv/dvrs/{$dvrId}");

            if ($response->successful()) {
                // Clear stored DVR ID if it matches
                if ((string) $this->integration->plex_dvr_id === $dvrId) {
                    $this->integration->update([
                        'plex_dvr_id' => null,
                        'plex_dvr_tuners' => null,
                    ]);
                }
            }

            return [
                'success' => $response->successful(),
                'message' => $response->successful() ? 'DVR removed from Plex' : 'Failed: '.$response->status(),
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get channel lineup for a specific DVR.
     */
    public function getDvrChannels(string $dvrId): array
    {
        try {
            $response = $this->client()->get("/livetv/dvrs/{$dvrId}/channels");

            if (! $response->successful()) {
                return ['success' => false, 'message' => 'Failed to fetch channels: '.$response->status()];
            }

            $channels = collect($response->json('MediaContainer.Metadata', []))
                ->map(fn (array $ch) => [
                    'id' => $ch['ratingKey'] ?? null,
                    'title' => $ch['title'] ?? 'Unknown',
                    'number' => $ch['index'] ?? null,
                    'enabled' => ($ch['enabled'] ?? true) !== false,
                    'thumb' => $ch['thumb'] ?? null,
                ]);

            return ['success' => true, 'data' => $channels];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get DVR recordings (scheduled and completed).
     *
     * @return array{success: bool, data?: Collection, message?: string}
     */
    public function getRecordings(): array
    {
        try {
            $response = $this->client()->get('/media/subscriptions');

            if (! $response->successful()) {
                return ['success' => false, 'message' => 'Failed to fetch recordings: '.$response->status()];
            }

            $recordings = collect($response->json('MediaContainer.MediaSubscription', []))
                ->map(fn (array $rec) => [
                    'id' => $rec['key'] ?? null,
                    'title' => $rec['title'] ?? 'Unknown',
                    'type' => $rec['type'] ?? 'unknown',
                    'target_library' => $rec['targetLibrarySectionID'] ?? null,
                    'created_at' => isset($rec['createdAt']) ? date('Y-m-d H:i:s', $rec['createdAt']) : null,
                ]);

            return ['success' => true, 'data' => $recordings];
        } catch (Exception $e) {
            Log::warning('PlexManagementService: Failed to get recordings', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Cancel/delete a DVR recording subscription.
     */
    public function cancelRecording(string $subscriptionId): array
    {
        try {
            $response = $this->client()->delete("/media/subscriptions/{$subscriptionId}");

            return [
                'success' => $response->successful(),
                'message' => $response->successful()
                    ? 'Recording cancelled'
                    : 'Failed to cancel recording: '.$response->status(),
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get all Plex libraries (not just movies/shows).
     *
     * @return array{success: bool, data?: Collection, message?: string}
     */
    public function getAllLibraries(): array
    {
        try {
            $response = $this->client()->get('/library/sections');

            if (! $response->successful()) {
                return ['success' => false, 'message' => 'Failed to fetch libraries: '.$response->status()];
            }

            $libraries = collect($response->json('MediaContainer.Directory', []))
                ->map(fn (array $lib) => [
                    'id' => $lib['key'] ?? null,
                    'title' => $lib['title'] ?? 'Unknown',
                    'type' => $lib['type'] ?? 'unknown',
                    'agent' => $lib['agent'] ?? null,
                    'scanner' => $lib['scanner'] ?? null,
                    'language' => $lib['language'] ?? null,
                    'refreshing' => (bool) ($lib['refreshing'] ?? false),
                    'locations' => collect($lib['Location'] ?? [])->pluck('path')->toArray(),
                    'created_at' => isset($lib['createdAt']) ? date('Y-m-d H:i:s', $lib['createdAt']) : null,
                    'scanned_at' => isset($lib['scannedAt']) ? date('Y-m-d H:i:s', $lib['scannedAt']) : null,
                ]);

            return ['success' => true, 'data' => $libraries];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Trigger a library scan/refresh in Plex.
     */
    public function scanLibrary(string $sectionKey): array
    {
        try {
            $response = $this->client()->get("/library/sections/{$sectionKey}/refresh");

            return [
                'success' => $response->successful(),
                'message' => $response->successful()
                    ? 'Library scan started'
                    : 'Failed to start scan: '.$response->status(),
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Scan all libraries in Plex.
     */
    public function scanAllLibraries(): array
    {
        $result = $this->getAllLibraries();
        if (! $result['success']) {
            return $result;
        }

        $scanned = 0;
        $errors = [];

        foreach ($result['data'] as $library) {
            $scanResult = $this->scanLibrary($library['id']);
            if ($scanResult['success']) {
                $scanned++;
            } else {
                $errors[] = $library['title'];
            }
        }

        return [
            'success' => $scanned > 0,
            'message' => "Scan started for {$scanned} libraries".(! empty($errors) ? '. Failed: '.implode(', ', $errors) : ''),
        ];
    }

    /**
     * Get Plex server preferences/settings.
     *
     * @return array{success: bool, data?: array, message?: string}
     */
    public function getServerPreferences(): array
    {
        try {
            $response = $this->client()->get('/:/prefs');

            if (! $response->successful()) {
                return ['success' => false, 'message' => 'Failed to fetch preferences: '.$response->status()];
            }

            $settings = collect($response->json('MediaContainer.Setting', []))
                ->keyBy('id')
                ->map(fn (array $setting) => [
                    'id' => $setting['id'],
                    'label' => $setting['label'] ?? $setting['id'],
                    'value' => $setting['value'] ?? null,
                    'default' => $setting['default'] ?? null,
                    'type' => $setting['type'] ?? 'string',
                    'hidden' => (bool) ($setting['hidden'] ?? false),
                    'group' => $setting['group'] ?? 'general',
                ]);

            // Return DVR-relevant preferences
            $dvrKeys = [
                'DvrIncrementalEpgLoader',
                'DvrComskipRemoveIntermediates',
                'DvrComskipRemoveOriginal',
                'DvrComskipEnabled',
            ];

            $dvrPrefs = $settings->only($dvrKeys);

            return ['success' => true, 'data' => $dvrPrefs->toArray()];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get a summary of the Plex server state for dashboard display.
     *
     * @return array{success: bool, data?: array, message?: string}
     */
    public function getDashboardSummary(): array
    {
        $serverInfo = $this->getServerInfo();
        if (! $serverInfo['success']) {
            return $serverInfo;
        }

        $sessions = $this->getActiveSessions();
        $dvrs = $this->getDvrs();
        $libraries = $this->getAllLibraries();

        return [
            'success' => true,
            'data' => [
                'server' => $serverInfo['data'],
                'active_sessions' => $sessions['success'] ? $sessions['data']->count() : 0,
                'sessions' => $sessions['success'] ? $sessions['data']->toArray() : [],
                'dvr_count' => $dvrs['success'] ? $dvrs['data']->count() : 0,
                'dvrs' => $dvrs['success'] ? $dvrs['data']->toArray() : [],
                'library_count' => $libraries['success'] ? $libraries['data']->count() : 0,
                'libraries' => $libraries['success'] ? $libraries['data']->toArray() : [],
            ],
        ];
    }

    /**
     * Sync the DVR channel map for all tuners.
     *
     * Iterates over each registered tuner and syncs its channel map
     * with the current HDHR lineup.
     *
     * @return array{success: bool, message: string, mapped_channels?: int, changed?: bool}
     */
    public function syncDvrChannels(): array
    {
        $tuners = $this->integration->plex_dvr_tuners ?? [];
        $dvrId = $this->integration->plex_dvr_id;

        if (! $dvrId || empty($tuners)) {
            return ['success' => false, 'message' => 'DVR not fully configured. Register a DVR tuner first.'];
        }

        $totalMapped = 0;
        $anyChanged = false;
        $errors = [];

        foreach ($tuners as $tuner) {
            $deviceKey = $tuner['device_key'] ?? null;
            $playlistUuid = $tuner['playlist_uuid'] ?? null;
            if (! $deviceKey || ! $playlistUuid) {
                continue;
            }

            $result = $this->syncDvrChannelsForTuner($deviceKey, $playlistUuid);
            if ($result['success']) {
                $totalMapped += $result['mapped_channels'] ?? 0;
                $anyChanged = $anyChanged || ($result['changed'] ?? false);
            } else {
                $errors[] = "{$playlistUuid}: {$result['message']}";
            }
        }

        if (! empty($errors) && $totalMapped === 0) {
            return ['success' => false, 'message' => implode('; ', $errors)];
        }

        $tunerCount = count($tuners);
        $message = $anyChanged
            ? "{$totalMapped} channels synced across {$tunerCount} tuner(s)"
            : "{$totalMapped} channels in sync across {$tunerCount} tuner(s)";

        // Trigger EPG refresh after channel sync so Plex re-fetches the guide
        // and discovers lineup identifiers for newly added channels
        if ($anyChanged) {
            try {
                $this->client()->withBody('')->post('/butler/RefreshEPGGuides');
                $message .= ' — EPG refresh triggered';
            } catch (Exception) {
                // Non-critical: EPG will refresh on next scheduled cycle
            }
        }

        return [
            'success' => true,
            'message' => $message,
            'mapped_channels' => $totalMapped,
            'changed' => $anyChanged,
        ];
    }

    /**
     * Sync the channel map for a single tuner (device + playlist).
     *
     * @return array{success: bool, message: string, mapped_channels?: int, changed?: bool}
     */
    public function syncDvrChannelsForTuner(string $deviceKey, string $playlistUuid): array
    {
        try {
            // Fetch current HDHR lineup from our own endpoint
            $appPort = config('app.port', 36400);
            $lineupUrl = "http://localhost:{$appPort}/{$playlistUuid}/hdhr/lineup.json";
            $lineupResponse = Http::timeout(15)->get($lineupUrl);

            if (! $lineupResponse->successful()) {
                return ['success' => false, 'message' => 'Could not fetch HDHR lineup (HTTP '.$lineupResponse->status().')'];
            }

            $hdhrLineup = $lineupResponse->json();
            if (! is_array($hdhrLineup)) {
                return ['success' => false, 'message' => 'HDHR lineup response is not a valid array'];
            }

            // Get the lineup ID directly from the Plex DVR data (most reliable)
            $lineupId = $this->getDvrLineupId();

            if (! $lineupId) {
                // Fallback: rebuild from our known URL
                $epgUrl = $this->buildExternalUrl($playlistUuid, 'epg.xml');
                $lineupId = $this->buildLineupId($epgUrl);
                Log::warning('PlexManagementService: Could not get lineup ID from DVR, using fallback', [
                    'integration_id' => $this->integration->id,
                    'fallback_lineup_id' => $lineupId,
                ]);
            }

            // Fetch Plex's current knowledge of the lineup channels
            $lineupChannelsResponse = $this->client()->get('/livetv/epg/lineupchannels', [
                'lineup' => $lineupId,
            ]);

            if (! $lineupChannelsResponse->successful()) {
                Log::warning('PlexManagementService: Failed to fetch lineup channels', [
                    'integration_id' => $this->integration->id,
                    'device_key' => $deviceKey,
                    'lineup_id' => $lineupId,
                    'status' => $lineupChannelsResponse->status(),
                ]);

                return ['success' => false, 'message' => 'Failed to fetch lineup channels from Plex (HTTP '.$lineupChannelsResponse->status().')'];
            }

            // Build the channel map payload
            $lineupChannelsPayload = $lineupChannelsResponse->json('MediaContainer') ?? [];

            Log::debug('PlexManagementService: Lineup channels response', [
                'integration_id' => $this->integration->id,
                'lineup_id' => $lineupId,
                'media_container_keys' => array_keys($lineupChannelsPayload),
                'channel_count' => is_array($lineupChannelsPayload['Channel'] ?? null) ? count($lineupChannelsPayload['Channel']) : 0,
            ]);
            $channelMapPayload = $this->buildChannelMapPayload($hdhrLineup, $lineupChannelsPayload);

            if (empty($channelMapPayload['enabledIds'])) {
                return [
                    'success' => true,
                    'message' => 'No channels to map (lineup may be empty)',
                    'mapped_channels' => 0,
                    'changed' => false,
                ];
            }

            // Check current device channel mapping
            $devicesResult = $this->getDevices();
            $rawDvrsResponse = $this->client()->get('/livetv/dvrs');
            $rawDvrs = $rawDvrsResponse->json('MediaContainer.Dvr', []);
            $allDevices = $this->flattenAllDevices(
                $devicesResult['success'] ? ($devicesResult['data'] ?? []) : [],
                $rawDvrs
            );

            $currentDevice = null;
            foreach ($allDevices as $device) {
                if (($device['key'] ?? '') === $deviceKey) {
                    $currentDevice = $device;
                    break;
                }
            }

            $currentMappedIds = $this->extractChannelMappingIds($currentDevice ?? []);
            $desiredMappedIds = $channelMapPayload['enabledIds'];
            sort($currentMappedIds);
            sort($desiredMappedIds);

            if ($currentMappedIds === $desiredMappedIds) {
                return [
                    'success' => true,
                    'message' => count($desiredMappedIds).' channels in sync',
                    'mapped_channels' => count($desiredMappedIds),
                    'changed' => false,
                ];
            }

            // Update the channel map
            $updateResponse = $this->client()->withBody('')->put(
                "/media/grabbers/devices/{$deviceKey}/channelmap?".http_build_query($channelMapPayload['payload'])
            );

            if (! $updateResponse->successful()) {
                return ['success' => false, 'message' => 'Failed to update channel map (HTTP '.$updateResponse->status().')'];
            }

            Log::info('PlexManagementService: Channel map updated', [
                'integration_id' => $this->integration->id,
                'device_key' => $deviceKey,
                'playlist_uuid' => $playlistUuid,
                'previous_count' => count($currentMappedIds),
                'new_count' => count($desiredMappedIds),
            ]);

            return [
                'success' => true,
                'message' => count($desiredMappedIds).' channels synced to Plex (was '.count($currentMappedIds).')',
                'mapped_channels' => count($desiredMappedIds),
                'changed' => true,
            ];
        } catch (Exception $e) {
            Log::error('PlexManagementService: Failed to sync DVR channels for tuner', [
                'integration_id' => $this->integration->id,
                'device_key' => $deviceKey,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Remove a single tuner (device) from the DVR.
     *
     * Removes the device from Plex and from the stored tuners array.
     * If it was the last tuner, removes the entire DVR.
     *
     * @return array{success: bool, message: string}
     */
    public function removeTuner(string $deviceKey): array
    {
        try {
            $dvrId = $this->integration->plex_dvr_id;
            if (! $dvrId) {
                return ['success' => false, 'message' => 'No DVR registered'];
            }

            $tuners = $this->integration->plex_dvr_tuners ?? [];
            $remainingTuners = array_values(array_filter($tuners, fn (array $t): bool => ($t['device_key'] ?? '') !== $deviceKey));

            if (empty($remainingTuners)) {
                // Last tuner — remove the entire DVR
                return $this->removeDvr($dvrId);
            }

            // Remove device from DVR
            $this->client()->delete("/livetv/dvrs/{$dvrId}/devices/{$deviceKey}");

            $this->integration->update([
                'plex_dvr_tuners' => $remainingTuners,
            ]);

            return [
                'success' => true,
                'message' => 'Tuner removed. '.count($remainingTuners).' tuner(s) remaining.',
            ];
        } catch (Exception $e) {
            Log::error('PlexManagementService: Failed to remove tuner', [
                'integration_id' => $this->integration->id,
                'device_key' => $deviceKey,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Build an external URL for HDHR/EPG endpoints.
     * Uses app.url with scheme handling.
     */
    protected function buildExternalUrl(string $playlistUuid, string $suffix): string
    {
        $appUrl = rtrim(config('app.url'), '/');
        if (! parse_url($appUrl, PHP_URL_SCHEME)) {
            $appUrl = 'http://'.$appUrl;
        }
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'http';
        $host = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';
        $port = parse_url($appUrl, PHP_URL_PORT) ?: config('app.port', 36400);

        return "{$scheme}://{$host}:{$port}/{$playlistUuid}/{$suffix}";
    }

    /**
     * Fetch the lineup ID from the Plex DVR data.
     *
     * This is more reliable than rebuilding it from scratch because Plex
     * stores the exact lineup ID used during DVR creation.
     */
    protected function getDvrLineupId(): ?string
    {
        $dvrId = (string) ($this->integration->plex_dvr_id ?? '');
        if (! $dvrId) {
            return null;
        }

        try {
            $response = $this->client()->get('/livetv/dvrs');
            if (! $response->successful()) {
                return null;
            }

            $selectedDvr = null;
            foreach ($response->json('MediaContainer.Dvr', []) as $dvr) {
                if ((string) ($dvr['key'] ?? '') === $dvrId) {
                    $selectedDvr = $dvr;
                    break;
                }
            }

            if (! $selectedDvr) {
                return null;
            }

            // Headendarr-style: check Lineup array entries first (more reliable)
            $lineupEntries = $selectedDvr['Lineup'] ?? [];
            if (! is_array($lineupEntries)) {
                $lineupEntries = [$lineupEntries];
            } elseif (! empty($lineupEntries) && ! array_is_list($lineupEntries)) {
                // Single lineup entry returned as associative array
                $lineupEntries = [$lineupEntries];
            }

            // First pass: match by title
            foreach ($lineupEntries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $title = trim((string) ($entry['title'] ?? ''));
                $id = trim((string) ($entry['id'] ?? ''));
                if ($title && $id && stripos($title, 'xmltv') !== false) {
                    Log::debug('PlexManagementService: Resolved lineup ID from Lineup array (by title)', [
                        'integration_id' => $this->integration->id,
                        'lineup_id' => $id,
                        'title' => $title,
                    ]);

                    return $id;
                }
            }

            // Second pass: match by lineup ID containing our EPG URL pattern
            foreach ($lineupEntries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $id = trim((string) ($entry['id'] ?? ''));
                if ($id && str_contains($id, 'tv.plex.providers.epg.xmltv')) {
                    Log::debug('PlexManagementService: Resolved lineup ID from Lineup array (by provider)', [
                        'integration_id' => $this->integration->id,
                        'lineup_id' => $id,
                    ]);

                    return $id;
                }
            }

            // Third: fall back to top-level lineup field
            $topLevel = trim((string) ($selectedDvr['lineup'] ?? ''));
            if ($topLevel) {
                Log::debug('PlexManagementService: Resolved lineup ID from top-level field', [
                    'integration_id' => $this->integration->id,
                    'lineup_id' => $topLevel,
                ]);

                return $topLevel;
            }
        } catch (Exception $e) {
            Log::warning('PlexManagementService: Failed to fetch DVR lineup ID', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Build the channel map payload for Plex from HDHR lineup and Plex lineup channels.
     *
     * @return array{payload: array, enabledIds: list<string>, unmatched: list<string>}
     */
    protected function buildChannelMapPayload(array $hdhrLineup, array $lineupChannelsPayload): array
    {
        // Build a number→identifier map from Plex's lineup channels.
        // Plex returns channels under "Channel" key with "number" and "lineupIdentifier" fields.
        // Handle single-channel responses: Plex may return a single object instead of an array.
        $channels = $lineupChannelsPayload['Channel'] ?? [];
        if (is_array($channels) && ! empty($channels) && ! array_is_list($channels)) {
            // Single channel returned as an associative array — wrap it in a list
            $channels = [$channels];
        }

        $numberMap = [];
        foreach ($channels as $channel) {
            if (! is_array($channel)) {
                continue;
            }
            $number = trim((string) ($channel['number'] ?? $channel['channelNumber'] ?? $channel['channel'] ?? ''));
            $identifier = trim((string) ($channel['lineupIdentifier'] ?? $channel['id'] ?? $channel['channelIdentifier'] ?? $channel['key'] ?? ''));
            if ($number && $identifier) {
                $numberMap[$number] = $identifier;
            }
        }

        $enabledIds = [];
        $unmatched = [];
        $seen = [];

        foreach ($hdhrLineup as $channel) {
            if (! is_array($channel)) {
                continue;
            }
            $guideNumber = trim((string) ($channel['GuideNumber'] ?? $channel['channel_number'] ?? ''));
            if (! $guideNumber) {
                continue;
            }

            $matchedId = $numberMap[$guideNumber] ?? $guideNumber;
            if (isset($seen[$matchedId])) {
                continue;
            }
            $seen[$matchedId] = true;
            $enabledIds[] = (string) $matchedId;
        }

        $payload = ['channelsEnabled' => implode(',', $enabledIds)];
        foreach ($enabledIds as $id) {
            $payload["channelMappingByKey[{$id}]"] = $id;
            $payload["channelMapping[{$id}]"] = $id;
        }

        Log::debug('PlexManagementService: Built channel map payload', [
            'lineup_channels_from_plex' => count($numberMap),
            'hdhr_lineup_channels' => count($hdhrLineup),
            'enabled_ids' => count($enabledIds),
            'unmatched' => count($unmatched),
        ]);

        return [
            'payload' => $payload,
            'enabledIds' => $enabledIds,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * Extract current channel mapping IDs from a Plex device payload.
     *
     * @return list<string>
     */
    protected function extractChannelMappingIds(array $devicePayload): array
    {
        $mappedIds = [];
        $seen = [];

        $items = $devicePayload['ChannelMapping'] ?? [];
        if (is_array($items) && ! empty($items) && ! array_is_list($items)) {
            $items = [$items];
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $enabled = trim($item['enabled'] ?? '1');
            if (in_array($enabled, ['0', 'false'])) {
                continue;
            }
            $identifier = trim($item['lineupIdentifier'] ?? $item['channelKey'] ?? '');
            if ($identifier && ! isset($seen[$identifier])) {
                $mappedIds[] = $identifier;
                $seen[$identifier] = true;
            }
        }

        return $mappedIds;
    }
}
