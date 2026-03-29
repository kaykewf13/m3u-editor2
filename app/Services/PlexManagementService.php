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
            ->retry(2, 1000)
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
     * Register an m3u-editor playlist's HDHR endpoint as a DVR tuner in Plex.
     *
     * Plex DVR setup flow:
     * 1. Plex probes the HDHR device at {hdhrBaseUrl}/discover.json
     * 2. POST /livetv/dvrs with the device URI as a query parameter
     * 3. Configure the XMLTV guide source for the DVR
     *
     * @param  string  $hdhrBaseUrl  The HDHR base URL reachable by Plex (e.g., http://192.168.1.x:36400/{uuid}/hdhr)
     * @param  string  $epgUrl  The EPG XML URL reachable by Plex
     */
    public function addDvrDevice(string $hdhrBaseUrl, string $epgUrl): array
    {
        try {
            // Step 1: Verify Plex can reach the HDHR device
            $verifyResponse = Http::timeout(10)->get($hdhrBaseUrl.'/discover.json');
            if (! $verifyResponse->successful()) {
                return [
                    'success' => false,
                    'message' => 'Cannot reach HDHR device at '.$hdhrBaseUrl.'/discover.json — Plex needs to access this URL. Check your network/APP_URL configuration.',
                ];
            }

            // Step 2: Tell Plex to add the HDHR device as a DVR tuner
            $discoverResponse = $this->client()->post('/livetv/dvrs?uri='.urlencode($hdhrBaseUrl));

            if (! $discoverResponse->successful()) {
                $body = $discoverResponse->body();
                Log::warning('PlexManagementService: DVR registration failed', [
                    'integration_id' => $this->integration->id,
                    'hdhr_url' => $hdhrBaseUrl,
                    'status' => $discoverResponse->status(),
                    'response' => $body,
                ]);

                return [
                    'success' => false,
                    'message' => 'Plex rejected the DVR registration (HTTP '.$discoverResponse->status().'). Ensure the HDHR URL is reachable from Plex and returns valid discover.json/lineup.json responses.',
                ];
            }

            $dvrData = $discoverResponse->json('MediaContainer.Dvr.0', []);
            $dvrId = $dvrData['key'] ?? null;

            // Step 3: If we got a DVR ID, configure XMLTV guide
            if ($dvrId && $epgUrl) {
                $this->configureGuide($dvrId, $epgUrl);
            }

            // Store the DVR ID on the integration
            if ($dvrId) {
                $this->integration->update(['plex_dvr_id' => $dvrId]);
            }

            return [
                'success' => true,
                'message' => 'HDHR device registered in Plex as DVR tuner'.($dvrId ? ' (DVR ID: '.$dvrId.')' : ''),
                'dvr_id' => $dvrId,
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
     * Configure XMLTV guide data for a DVR.
     */
    public function configureGuide(string $dvrId, string $epgUrl): array
    {
        try {
            $response = $this->client()->put("/livetv/dvrs/{$dvrId}?lineup=".urlencode($epgUrl).'&lineupType=xmltv');

            return [
                'success' => $response->successful(),
                'message' => $response->successful()
                    ? 'Guide data configured'
                    : 'Failed to configure guide: '.$response->status(),
            ];
        } catch (Exception $e) {
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
                    $this->integration->update(['plex_dvr_id' => null]);
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
}
