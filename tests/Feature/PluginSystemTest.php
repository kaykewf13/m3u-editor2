<?php

use App\Enums\Status;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\ExtensionPlugin;
use App\Models\ExtensionPluginRun;
use App\Models\ExtensionPluginRunLog;
use App\Models\Playlist;
use App\Models\PluginEpgRepairScanCandidate;
use App\Models\User;
use App\Filament\Resources\ExtensionPlugins\Pages\ViewPluginRun;
use App\Plugins\PluginManager;
use App\Plugins\PluginSchemaMapper;
use App\Jobs\ExecutePluginInvocation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

function discoverPluginForTests(bool $enabled = false): ExtensionPlugin
{
    $pluginManager = app(PluginManager::class);
    $plugin = $pluginManager->discover()[0];
    $plugin = $pluginManager->reinstall($plugin->fresh());

    if ($enabled) {
        $plugin->update(['enabled' => true]);
    }

    return $plugin->fresh();
}

it('discovers the bundled epg repair plugin as a valid local plugin', function () {
    $plugins = app(PluginManager::class)->discover();

    expect($plugins)->toHaveCount(1);

    $plugin = ExtensionPlugin::query()->where('plugin_id', 'epg-repair')->first();

    expect($plugin)->not->toBeNull();
    expect($plugin->validation_status)->toBe('valid');
    expect($plugin->available)->toBeTrue();
    expect($plugin->class_name)->toBe('AppLocalPlugins\\EpgRepair\\Plugin');
    expect($plugin->capabilities)->toContain('epg_repair');
    expect($plugin->actions)->toBeArray();
});

it('validates a discovered plugin from the registry', function () {
    $plugin = app(PluginManager::class)->discover()[0];

    $validated = app(PluginManager::class)->validate($plugin);

    expect($validated->validation_status)->toBe('valid');
    expect($validated->validation_errors)->toBe([]);
});

it('scans and applies epg repairs through the plugin manager', function () {
    $pluginManager = app(PluginManager::class);
    $plugin = discoverPluginForTests(true);

    $user = User::create([
        'name' => 'Plugin Tester',
        'email' => 'plugin-test-'.Str::random(10).'@example.com',
        'password' => Hash::make('password'),
        'email_verified_at' => now(),
    ]);

    $playlist = Playlist::create([
        'name' => 'Plugin Test Playlist',
        'uuid' => (string) Str::uuid(),
        'url' => 'http://example.test/playlist.m3u',
        'status' => Status::Completed,
        'prefix' => 'test',
        'channels' => 1,
        'synced' => now(),
        'id_channel_by' => 'stream_id',
        'user_id' => $user->id,
    ]);

    $epg = Epg::create([
        'name' => 'Plugin Test EPG',
        'url' => 'http://example.test/epg.xml',
        'user_id' => $user->id,
        'status' => Status::Completed,
    ]);

    $epgChannel = EpgChannel::create([
        'name' => 'BBC One HD',
        'display_name' => 'BBC One HD',
        'lang' => 'en',
        'channel_id' => 'bbc-one-hd',
        'epg_id' => $epg->id,
        'user_id' => $user->id,
    ]);

    $channel = Channel::create([
        'name' => 'BBC One HD',
        'title' => 'BBC One HD',
        'enabled' => true,
        'channel' => 1,
        'shift' => 0,
        'url' => 'http://stream.example.test/live.ts',
        'logo' => '',
        'group' => 'Test',
        'stream_id' => '1',
        'lang' => 'en',
        'country' => 'GB',
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'group_id' => null,
        'is_vod' => false,
        'epg_channel_id' => null,
    ]);

    $scanRun = $pluginManager->executeAction($plugin->fresh(), 'scan', [
        'playlist_id' => $playlist->id,
        'epg_id' => $epg->id,
        'hours_ahead' => 12,
        'confidence_threshold' => 0.6,
    ], [
        'trigger' => 'manual',
        'dry_run' => true,
        'user_id' => $user->id,
    ]);

    expect($scanRun->status)->toBe('completed');
    expect(data_get($scanRun->result, 'data.channels_preview'))->toHaveCount(1);
    expect(data_get($scanRun->result, 'data.channels_preview.0.issue'))->toBe('unmapped');
    expect(data_get($scanRun->result, 'data.channels_preview.0.playlist_name'))->toBe('Plugin Test Playlist');
    expect(data_get($scanRun->result, 'data.channels_preview.0.suggested_epg_channel_id'))->toBe($epgChannel->id);
    expect(data_get($scanRun->result, 'data.channels_preview.0.suggested_epg_source_name'))->toBe('Plugin Test EPG');
    expect(data_get($scanRun->result, 'data.channels_preview.0.repairable'))->toBeTrue();
    expect(data_get($scanRun->result, 'data.channels_total_count'))->toBe(1);
    expect(data_get($scanRun->result, 'data.channels_truncated'))->toBeFalse();
    expect(data_get($scanRun->result, 'data.issue_breakdown.unmapped'))->toBe(1);
    expect(data_get($scanRun->result, 'data.decision_breakdown.repairable'))->toBe(1);
    expect(data_get($scanRun->result, 'data.confidence_breakdown.exact'))->toBe(1);
    expect(data_get($scanRun->result, 'data.totals.epg_channels_available'))->toBe(1);
    expect(Storage::disk('local')->exists(data_get($scanRun->result, 'data.report.path')))->toBeTrue();
    expect(Storage::disk('local')->get(data_get($scanRun->result, 'data.report.path')))->toContain('BBC One HD');
    expect(PluginEpgRepairScanCandidate::query()->where('extension_plugin_run_id', $scanRun->id)->count())->toBe(1);
    $scanCandidate = PluginEpgRepairScanCandidate::query()->where('extension_plugin_run_id', $scanRun->id)->first();
    expect($scanCandidate?->review_status)->toBe('pending');
    expect($scanCandidate?->suggested_epg_channel_id)->toBe($epgChannel->id);
    expect($scanCandidate?->playlist_name)->toBe('Plugin Test Playlist');
    expect($scanRun->progress)->toBe(100);
    expect(data_get($scanRun->result, 'status'))->toBe('completed');
    expect($scanRun->last_heartbeat_at)->not->toBeNull();
    expect($scanRun->run_state)->toBeNull();
    expect($scanRun->logs()->count())->toBeGreaterThanOrEqual(2);
    expect($scanRun->logs()->pluck('message')->join(' '))->toContain('Starting EPG Repair scan.');

    $applyRun = $pluginManager->executeAction($plugin->fresh(), 'apply', [
        'playlist_id' => $playlist->id,
        'epg_id' => $epg->id,
        'hours_ahead' => 12,
        'confidence_threshold' => 0.6,
    ], [
        'trigger' => 'manual',
        'dry_run' => false,
        'user_id' => $user->id,
    ]);

    expect($applyRun->status)->toBe('completed');
    expect($applyRun->progress)->toBe(100);
    expect($applyRun->last_heartbeat_at)->not->toBeNull();
    expect($applyRun->run_state)->toBeNull();

    $channel->refresh();

    expect($channel->epg_channel_id)->toBe($epgChannel->id);
    expect(data_get($applyRun->result, 'data.totals.repairs_applied'))->toBe(1);
    expect($applyRun->logs()->pluck('message')->join(' '))->toContain('Applied EPG repair to channel.');
    expect(PluginEpgRepairScanCandidate::query()->where('extension_plugin_run_id', $applyRun->id)->where('applied', true)->count())->toBe(1);
    expect(ExtensionPluginRunLog::query()->count())->toBeGreaterThanOrEqual(4);
});

it('explains when a scan has no enabled live channels to inspect', function () {
    $pluginManager = app(PluginManager::class);
    $plugin = discoverPluginForTests(true);

    $user = User::create([
        'name' => 'Empty Playlist Tester',
        'email' => 'empty-playlist-'.Str::random(10).'@example.com',
        'password' => Hash::make('password'),
        'email_verified_at' => now(),
    ]);

    $playlist = Playlist::create([
        'name' => 'Empty Playlist',
        'uuid' => (string) Str::uuid(),
        'url' => 'http://example.test/empty.m3u',
        'status' => Status::Completed,
        'prefix' => 'empty',
        'channels' => 0,
        'synced' => now(),
        'id_channel_by' => 'stream_id',
        'user_id' => $user->id,
    ]);

    $epg = Epg::create([
        'name' => 'Empty Playlist EPG',
        'url' => 'http://example.test/epg.xml',
        'user_id' => $user->id,
        'status' => Status::Completed,
    ]);

    $scanRun = $pluginManager->executeAction($plugin->fresh(), 'scan', [
        'playlist_id' => $playlist->id,
        'epg_id' => $epg->id,
        'hours_ahead' => 12,
        'confidence_threshold' => 0.6,
    ], [
        'trigger' => 'manual',
        'dry_run' => true,
        'user_id' => $user->id,
    ]);

    expect($scanRun->status)->toBe('completed');
    expect($scanRun->summary)->toContain('no enabled live channels');
    expect(data_get($scanRun->result, 'data.totals.channels_scanned'))->toBe(0);
    expect($scanRun->logs()->pluck('message')->join(' '))->toContain('no enabled live channels');
});

it('can compare a channel against all owned epg sources during scan', function () {
    $pluginManager = app(PluginManager::class);
    $plugin = discoverPluginForTests(true);

    $user = User::create([
        'name' => 'Compare Tester',
        'email' => 'compare-'.Str::random(10).'@example.com',
        'password' => Hash::make('password'),
        'email_verified_at' => now(),
    ]);

    $playlist = Playlist::create([
        'name' => 'Compare Playlist',
        'uuid' => (string) Str::uuid(),
        'url' => 'http://example.test/compare.m3u',
        'status' => Status::Completed,
        'prefix' => 'compare',
        'channels' => 1,
        'synced' => now(),
        'id_channel_by' => 'stream_id',
        'user_id' => $user->id,
    ]);

    $selectedEpg = Epg::create([
        'name' => 'Regional EPG',
        'url' => 'http://example.test/regional.xml',
        'user_id' => $user->id,
        'status' => Status::Completed,
    ]);

    $betterEpg = Epg::create([
        'name' => 'Primary EPG',
        'url' => 'http://example.test/primary.xml',
        'user_id' => $user->id,
        'status' => Status::Completed,
    ]);

    EpgChannel::create([
        'name' => 'BBC One London',
        'display_name' => 'BBC One London',
        'lang' => 'en',
        'channel_id' => 'bbc-one-london',
        'epg_id' => $selectedEpg->id,
        'user_id' => $user->id,
    ]);

    $betterChannel = EpgChannel::create([
        'name' => 'BBC One HD',
        'display_name' => 'BBC One HD',
        'lang' => 'en',
        'channel_id' => 'bbc-one-hd',
        'epg_id' => $betterEpg->id,
        'user_id' => $user->id,
    ]);

    Channel::create([
        'name' => 'BBC One HD',
        'title' => 'BBC One HD',
        'enabled' => true,
        'channel' => 1,
        'shift' => 0,
        'url' => 'http://stream.example.test/compare.ts',
        'logo' => '',
        'group' => 'Test',
        'stream_id' => 'compare-1',
        'lang' => 'en',
        'country' => 'GB',
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'group_id' => null,
        'is_vod' => false,
        'epg_channel_id' => null,
    ]);

    $scanRun = $pluginManager->executeAction($plugin->fresh(), 'scan', [
        'playlist_id' => $playlist->id,
        'epg_id' => $selectedEpg->id,
        'hours_ahead' => 12,
        'confidence_threshold' => 0.6,
        'source_scope' => 'all_owned',
    ], [
        'trigger' => 'manual',
        'dry_run' => true,
        'user_id' => $user->id,
    ]);

    expect($scanRun->status)->toBe('completed');
    expect(data_get($scanRun->result, 'data.source_scope'))->toBe('all_owned');
    expect(data_get($scanRun->result, 'data.totals.compared_epg_sources'))->toBe(2);
    expect(data_get($scanRun->result, 'data.channels_preview.0.decision'))->toBe('better_source_available');
    expect(data_get($scanRun->result, 'data.channels_preview.0.suggested_epg_channel_id'))->toBe($betterChannel->id);
    expect(data_get($scanRun->result, 'data.channels_preview.0.suggested_epg_source_name'))->toBe('Primary EPG');
    expect(data_get($scanRun->result, 'data.channels_preview.0.source_candidates_count'))->toBeGreaterThanOrEqual(1);
    expect(PluginEpgRepairScanCandidate::query()->where('extension_plugin_run_id', $scanRun->id)->first()?->suggested_epg_source_name)->toBe('Primary EPG');
});

it('blocks source switching during apply unless explicitly allowed', function () {
    $pluginManager = app(PluginManager::class);
    $plugin = discoverPluginForTests(true);

    $user = User::create([
        'name' => 'Apply Guard Tester',
        'email' => 'apply-guard-'.Str::random(10).'@example.com',
        'password' => Hash::make('password'),
        'email_verified_at' => now(),
    ]);

    $playlist = Playlist::create([
        'name' => 'Apply Guard Playlist',
        'uuid' => (string) Str::uuid(),
        'url' => 'http://example.test/apply-guard.m3u',
        'status' => Status::Completed,
        'prefix' => 'apply-guard',
        'channels' => 1,
        'synced' => now(),
        'id_channel_by' => 'stream_id',
        'user_id' => $user->id,
    ]);

    $currentEpg = Epg::create([
        'name' => 'Current EPG',
        'url' => 'http://example.test/current.xml',
        'user_id' => $user->id,
        'status' => Status::Completed,
    ]);

    $targetEpg = Epg::create([
        'name' => 'Target EPG',
        'url' => 'http://example.test/target.xml',
        'user_id' => $user->id,
        'status' => Status::Completed,
    ]);

    $currentMapping = EpgChannel::create([
        'name' => 'Sky Sports Main Event',
        'display_name' => 'Sky Sports Main Event',
        'lang' => 'en',
        'channel_id' => 'sky-sports-main-event-current',
        'epg_id' => $currentEpg->id,
        'user_id' => $user->id,
    ]);

    EpgChannel::create([
        'name' => 'Sky Sports Main Event',
        'display_name' => 'Sky Sports Main Event',
        'lang' => 'en',
        'channel_id' => 'sky-sports-main-event-target',
        'epg_id' => $targetEpg->id,
        'user_id' => $user->id,
    ]);

    $channel = Channel::create([
        'name' => 'Sky Sports Main Event',
        'title' => 'Sky Sports Main Event',
        'enabled' => true,
        'channel' => 1,
        'shift' => 0,
        'url' => 'http://stream.example.test/apply-guard.ts',
        'logo' => '',
        'group' => 'Sports',
        'stream_id' => 'apply-guard-1',
        'lang' => 'en',
        'country' => 'GB',
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'group_id' => null,
        'is_vod' => false,
        'epg_channel_id' => $currentMapping->id,
    ]);

    $applyRun = $pluginManager->executeAction($plugin->fresh(), 'apply', [
        'playlist_id' => $playlist->id,
        'epg_id' => $targetEpg->id,
        'hours_ahead' => 12,
        'confidence_threshold' => 0.6,
        'apply_scope' => 'all_repairable',
        'allow_source_switch' => false,
        'max_repairs' => 50,
    ], [
        'trigger' => 'manual',
        'dry_run' => false,
        'user_id' => $user->id,
    ]);

    $channel->refresh();

    expect($applyRun->status)->toBe('completed');
    expect($channel->epg_channel_id)->toBe($currentMapping->id);
    expect(data_get($applyRun->result, 'data.totals.repairs_applied'))->toBe(0);
    expect(data_get($applyRun->result, 'data.apply_outcome_breakdown.source_switch_blocked'))->toBe(1);
    expect(data_get($applyRun->result, 'data.channels_preview.0.apply_outcome'))->toBe('source_switch_blocked');
});

it('lets operators review visible candidates before applying reviewed repairs', function () {
    $pluginManager = app(PluginManager::class);
    $plugin = discoverPluginForTests(true);

    $user = User::factory()->create([
        'permissions' => ['use_tools'],
    ]);

    $playlist = Playlist::create([
        'name' => 'Reviewed Apply Playlist',
        'uuid' => (string) Str::uuid(),
        'url' => 'http://example.test/reviewed-apply.m3u',
        'status' => Status::Completed,
        'prefix' => 'reviewed',
        'channels' => 1,
        'synced' => now(),
        'id_channel_by' => 'stream_id',
        'user_id' => $user->id,
    ]);

    $epg = Epg::create([
        'name' => 'Reviewed Apply EPG',
        'url' => 'http://example.test/reviewed-apply.xml',
        'user_id' => $user->id,
        'status' => Status::Completed,
    ]);

    $epgChannel = EpgChannel::create([
        'name' => 'Discovery Channel HD',
        'display_name' => 'Discovery Channel HD',
        'lang' => 'en',
        'channel_id' => 'discovery-channel-hd',
        'epg_id' => $epg->id,
        'user_id' => $user->id,
    ]);

    $channel = Channel::create([
        'name' => 'Discovery Channel HD',
        'title' => 'Discovery Channel HD',
        'enabled' => true,
        'channel' => 7,
        'shift' => 0,
        'url' => 'http://stream.example.test/discovery.ts',
        'logo' => '',
        'group' => 'Docs',
        'stream_id' => 'reviewed-1',
        'lang' => 'en',
        'country' => 'US',
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'group_id' => null,
        'is_vod' => false,
        'epg_channel_id' => null,
    ]);

    $scanRun = $pluginManager->executeAction($plugin->fresh(), 'scan', [
        'playlist_id' => $playlist->id,
        'epg_id' => $epg->id,
        'hours_ahead' => 12,
        'confidence_threshold' => 0.6,
    ], [
        'trigger' => 'manual',
        'dry_run' => true,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user);

    Livewire::test(ViewPluginRun::class, [
        'record' => $plugin->id,
        'run' => $scanRun->id,
    ])->call('markReviewDecision', $channel->id, 'approved');

    $scanRun->refresh();
    $scanCandidate = PluginEpgRepairScanCandidate::query()
        ->where('extension_plugin_run_id', $scanRun->id)
        ->where('channel_id', $channel->id)
        ->first();

    expect(data_get($scanRun->result, 'data.review.counts.approved'))->toBe(1);
    expect(data_get($scanRun->result, 'data.review.counts.pending'))->toBe(0);
    expect(data_get($scanRun->result, 'data.review.decisions.'.$channel->id.'.status'))->toBe('approved');
    expect($scanCandidate?->review_status)->toBe('approved');
    expect($scanCandidate?->reviewed_by_user_id)->toBe($user->id);

    $applyReviewedRun = $pluginManager->executeAction($plugin->fresh(), 'apply_reviewed', [
        'source_run_id' => $scanRun->id,
    ], [
        'trigger' => 'manual',
        'dry_run' => false,
        'user_id' => $user->id,
    ]);

    $channel->refresh();
    $scanRun->refresh();

    expect($applyReviewedRun->status)->toBe('completed');
    expect($channel->epg_channel_id)->toBe($epgChannel->id);
    expect(data_get($applyReviewedRun->result, 'data.totals.repairs_applied'))->toBe(1);
    expect(data_get($applyReviewedRun->result, 'data.apply_outcome_breakdown.applied'))->toBe(1);
    expect(data_get($scanRun->result, 'data.review.decisions.'.$channel->id.'.status'))->toBe('applied');
    expect(PluginEpgRepairScanCandidate::query()->where('extension_plugin_run_id', $applyReviewedRun->id)->where('applied', true)->count())->toBe(1);
    expect(PluginEpgRepairScanCandidate::query()->where('extension_plugin_run_id', $scanRun->id)->where('channel_id', $channel->id)->first()?->review_status)->toBe('applied');
});

it('prefills plugin action fields from saved settings when declared', function () {
    $plugin = discoverPluginForTests();

    $plugin->forceFill([
        'settings' => [
            'default_playlist_id' => 42,
            'default_epg_id' => 77,
            'hours_ahead' => 24,
            'confidence_threshold' => 0.8,
        ],
    ]);

    $components = collect(app(PluginSchemaMapper::class)->actionComponents($plugin, 'scan'))
        ->keyBy(fn ($component) => $component->getName());

    expect($components['playlist_id']->getDefaultState())->toBe(42);
    expect($components['epg_id']->getDefaultState())->toBe(77);
    expect($components['hours_ahead']->getDefaultState())->toBe(24);
    expect($components['confidence_threshold']->getDefaultState())->toBe(0.8);
});

it('records plugin-owned data declarations and preserves them on uninstall by default', function () {
    Storage::fake('local');

    $pluginManager = app(PluginManager::class);
    $plugin = discoverPluginForTests(true);

    Storage::disk('local')->put('plugin-reports/epg-repair/keep-me.csv', 'report');

    $plugin = $pluginManager->uninstall($plugin->fresh(), 'preserve');

    expect($plugin->isInstalled())->toBeFalse();
    expect($plugin->enabled)->toBeFalse();
    expect($plugin->last_cleanup_mode)->toBe('preserve');
    expect($plugin->uninstalled_at)->not->toBeNull();
    expect(data_get($plugin->data_ownership, 'directories'))->toContain('plugin-reports/epg-repair');
    expect(Storage::disk('local')->exists('plugin-reports/epg-repair/keep-me.csv'))->toBeTrue();
});

it('purges declared plugin-owned data and blocks execution until reinstall', function () {
    Storage::fake('local');

    $pluginManager = app(PluginManager::class);
    $plugin = discoverPluginForTests(true);

    Storage::disk('local')->put('plugin-reports/epg-repair/remove-me.csv', 'report');
    $user = User::factory()->create();
    $playlist = Playlist::create([
        'name' => 'Cleanup Playlist',
        'uuid' => (string) Str::uuid(),
        'url' => 'http://example.test/cleanup.m3u',
        'status' => Status::Completed,
        'prefix' => 'cleanup',
        'channels' => 1,
        'synced' => now(),
        'id_channel_by' => 'stream_id',
        'user_id' => $user->id,
    ]);
    $channel = Channel::create([
        'name' => 'Cleanup Channel',
        'title' => 'Cleanup Channel',
        'enabled' => true,
        'channel' => 1,
        'shift' => 0,
        'url' => 'http://stream.example.test/cleanup.ts',
        'logo' => '',
        'group' => 'Cleanup',
        'stream_id' => 'cleanup-1',
        'lang' => 'en',
        'country' => 'US',
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'group_id' => null,
        'is_vod' => false,
        'epg_channel_id' => null,
    ]);
    $run = ExtensionPluginRun::query()->create([
        'extension_plugin_id' => $plugin->id,
        'user_id' => $user->id,
        'status' => 'completed',
        'invocation_type' => 'action',
        'action' => 'scan',
        'trigger' => 'manual',
        'dry_run' => true,
    ]);
    PluginEpgRepairScanCandidate::query()->create([
        'extension_plugin_run_id' => $run->id,
        'channel_id' => $channel->id,
        'playlist_id' => $playlist->id,
        'playlist_name' => $playlist->name,
        'issue' => 'unmapped',
        'decision' => 'repairable',
        'repairable' => true,
        'review_status' => 'pending',
    ]);

    $plugin = $pluginManager->uninstall($plugin->fresh(), 'purge');

    expect($plugin->isInstalled())->toBeFalse();
    expect($plugin->last_cleanup_mode)->toBe('purge');
    expect(Storage::disk('local')->exists('plugin-reports/epg-repair/remove-me.csv'))->toBeFalse();
    expect(PluginEpgRepairScanCandidate::query()->count())->toBe(0);

    expect(fn () => $pluginManager->instantiate($plugin->fresh()))
        ->toThrow(\RuntimeException::class, 'has been uninstalled');

    $reinstalled = $pluginManager->reinstall($plugin->fresh());

    expect($reinstalled->isInstalled())->toBeTrue();
    expect($reinstalled->uninstalled_at)->toBeNull();
    expect($reinstalled->last_cleanup_mode)->toBeNull();
});

it('can rediscover a forgotten registry row without treating it as uninstall cleanup', function () {
    $pluginManager = app(PluginManager::class);
    $plugin = discoverPluginForTests();
    $pluginId = $plugin->plugin_id;

    $plugin->delete();

    expect(ExtensionPlugin::query()->where('plugin_id', $pluginId)->exists())->toBeFalse();

    $rediscovered = $pluginManager->discover()[0];

    expect($rediscovered->plugin_id)->toBe($pluginId);
    expect($rediscovered->isInstalled())->toBeTrue();
    expect($rediscovered->last_cleanup_mode)->toBeNull();
});

it('supports plugin lifecycle commands from the host', function () {
    Storage::fake('local');

    app(PluginManager::class)->discover();
    Storage::disk('local')->put('plugin-reports/epg-repair/cli-cleanup.csv', 'report');

    $this->artisan('plugins:uninstall', [
        'pluginId' => 'epg-repair',
        '--cleanup' => 'preserve',
    ])->assertSuccessful()
        ->expectsOutputToContain('uninstalled with cleanup mode [preserve]');

    expect(app(PluginManager::class)->findPluginById('epg-repair')?->isInstalled())->toBeFalse();
    expect(Storage::disk('local')->exists('plugin-reports/epg-repair/cli-cleanup.csv'))->toBeTrue();

    $this->artisan('plugins:reinstall', [
        'pluginId' => 'epg-repair',
    ])->assertSuccessful()
        ->expectsOutputToContain('Plugin [epg-repair] reinstalled.');

    expect(app(PluginManager::class)->findPluginById('epg-repair')?->isInstalled())->toBeTrue();

    $this->artisan('plugins:forget', [
        'pluginId' => 'epg-repair',
    ])->assertSuccessful()
        ->expectsOutputToContain('registry record deleted');

    expect(ExtensionPlugin::query()->where('plugin_id', 'epg-repair')->exists())->toBeFalse();
    expect(Storage::disk('local')->exists('plugin-reports/epg-repair/cli-cleanup.csv'))->toBeTrue();
});

it('reports plugin registry health through the doctor command', function () {
    Storage::fake('local');

    $plugin = app(PluginManager::class)->discover()[0];
    $plugin = app(PluginManager::class)->reinstall($plugin->fresh());

    $this->artisan('plugins:doctor')
        ->assertSuccessful()
        ->expectsOutputToContain('Plugin registry looks healthy.');

    $plugin->update([
        'enabled' => false,
        'installation_status' => 'uninstalled',
        'last_cleanup_mode' => 'purge',
        'data_ownership' => [
            'tables' => ['plugin_epg_repair_scan_candidates'],
            'directories' => ['plugin-reports/epg-repair'],
            'files' => [],
            'default_cleanup_policy' => 'preserve',
        ],
    ]);
    Storage::disk('local')->put('plugin-reports/epg-repair/orphan.csv', 'report');

    $this->artisan('plugins:doctor')
        ->assertSuccessful()
        ->expectsOutputToContain('still exists after a purge uninstall');
});

it('loads a plugin run detail page inside the plugin resource', function () {
    $plugin = discoverPluginForTests(true);

    $user = User::factory()->create([
        'permissions' => ['use_tools'],
    ]);

    $this->actingAs($user);

    $run = ExtensionPluginRun::query()->create([
        'extension_plugin_id' => $plugin->id,
        'user_id' => $user->id,
        'status' => 'running',
        'invocation_type' => 'action',
        'action' => 'scan',
        'trigger' => 'manual',
        'dry_run' => true,
        'payload' => ['playlist_id' => 123],
        'summary' => 'Queued for inspection.',
        'started_at' => now(),
    ]);

    $run->logs()->create([
        'level' => 'info',
        'message' => 'Plugin run started.',
        'context' => ['playlist_id' => 123],
    ]);

    Livewire::test(ViewPluginRun::class, [
        'record' => $plugin->id,
        'run' => $run->id,
    ])
        ->assertOk()
        ->assertSee('Plugin run started.')
        ->assertSee('Queued for inspection.');
});

it('marks stale runs, supports cancellation requests, and queues resume for stale runs', function () {
    $pluginManager = app(PluginManager::class);
    $plugin = discoverPluginForTests(true);

    $user = User::factory()->create([
        'permissions' => ['use_tools'],
    ]);

    $staleRun = ExtensionPluginRun::query()->create([
        'extension_plugin_id' => $plugin->id,
        'user_id' => $user->id,
        'status' => 'running',
        'invocation_type' => 'action',
        'action' => 'scan',
        'trigger' => 'manual',
        'dry_run' => true,
        'payload' => ['playlist_id' => 123],
        'progress' => 42,
        'progress_message' => 'Still working through checkpoint 3.',
        'last_heartbeat_at' => now()->subMinutes(20),
        'started_at' => now()->subMinutes(25),
        'run_state' => [
            'epg_repair' => [
                'last_channel_id' => 999,
                'channels_scanned' => 420,
            ],
        ],
    ]);

    expect($pluginManager->recoverStaleRuns())->toBe(1);

    $staleRun->refresh();

    expect($staleRun->status)->toBe('stale');
    expect($staleRun->stale_at)->not->toBeNull();
    expect(data_get($staleRun->result, 'status'))->toBe('stale');

    $runningRun = ExtensionPluginRun::query()->create([
        'extension_plugin_id' => $plugin->id,
        'user_id' => $user->id,
        'status' => 'running',
        'invocation_type' => 'action',
        'action' => 'scan',
        'trigger' => 'manual',
        'dry_run' => true,
        'payload' => ['playlist_id' => 456],
        'started_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    $pluginManager->requestCancellation($runningRun, $user->id);
    $runningRun->refresh();

    expect($runningRun->cancel_requested)->toBeTrue();
    expect($runningRun->cancel_requested_at)->not->toBeNull();
    expect($runningRun->progress_message)->toContain('Cancellation requested');

    Queue::fake();

    $pluginManager->resumeRun($staleRun, $user->id);

    Queue::assertPushed(ExecutePluginInvocation::class, function (ExecutePluginInvocation $job) use ($plugin, $staleRun, $user) {
        return $job->pluginId === $plugin->id
            && $job->invocationType === 'action'
            && $job->name === 'scan'
            && $job->options['existing_run_id'] === $staleRun->id
            && $job->options['user_id'] === $user->id;
    });
});
