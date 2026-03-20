<?php

use App\Enums\Status;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\ExtensionPlugin;
use App\Models\ExtensionPluginRun;
use App\Models\ExtensionPluginRunLog;
use App\Models\Playlist;
use App\Models\PluginInstallReview;
use App\Models\PluginEpgRepairScanCandidate;
use App\Models\User;
use App\Filament\Resources\ExtensionPlugins\Pages\ViewPluginRun;
use App\Plugins\PluginManager;
use App\Plugins\PluginSchemaMapper;
use App\Jobs\ExecutePluginInvocation;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('plugins.clamav.driver', 'fake');
    config()->set('plugins.install_mode', 'normal');
});

function approvePluginReviewForTests(string $sourcePath, bool $trust = true, bool $devSource = false): PluginInstallReview
{
    $pluginManager = app(PluginManager::class);
    $review = $pluginManager->stageDirectoryReview($sourcePath, null, $devSource);
    $review = $pluginManager->scanInstallReview($review);

    return $pluginManager->approveInstallReview($review, $trust);
}

function discoverPluginForTests(bool $enabled = false): ExtensionPlugin
{
    $pluginManager = app(PluginManager::class);
    $plugin = collect($pluginManager->discover())
        ->firstWhere('plugin_id', 'epg-repair');

    expect($plugin)->not->toBeNull();

    $plugin = $pluginManager->reinstall($plugin->fresh());

    if ($enabled) {
        approvePluginReviewForTests(base_path('plugins/epg-repair'), true);
        $plugin = $pluginManager->findPluginById('epg-repair');
        $plugin?->update(['enabled' => true]);
    }

    return $plugin->fresh();
}

function pluginReviewFixturePaths(string $pluginId): array
{
    return [
        'source' => storage_path('app/testing-plugin-sources/'.$pluginId),
        'archive' => storage_path('app/testing-plugin-archives/'.$pluginId.'.zip'),
        'sentinel' => storage_path('app/testing-plugin-sentinels/'.$pluginId.'.txt'),
    ];
}

function pluginReviewFixtureClassName(string $pluginId): string
{
    return 'AppLocalPlugins\\'.Str::studly(str_replace('-', ' ', $pluginId)).'\\Plugin';
}

function createReviewFixturePlugin(string $pluginId, bool $withSideEffect = false): array
{
    $paths = pluginReviewFixturePaths($pluginId);
    $classSegment = Str::studly(str_replace('-', ' ', $pluginId));

    File::deleteDirectory($paths['source']);
    File::ensureDirectoryExists($paths['source']);
    File::ensureDirectoryExists(dirname($paths['sentinel']));
    File::delete($paths['sentinel']);

    $manifest = [
        'id' => $pluginId,
        'name' => Str::title(str_replace('-', ' ', $pluginId)),
        'version' => '0.1.0',
        'description' => 'Temporary test plugin fixture.',
        'api_version' => config('plugins.api_version'),
        'entrypoint' => 'Plugin.php',
        'class' => "AppLocalPlugins\\{$classSegment}\\Plugin",
        'capabilities' => [],
        'hooks' => [],
        'permissions' => [],
        'settings' => [],
        'actions' => [],
        'schema' => [
            'tables' => [],
        ],
        'data_ownership' => [
            'plugin_id' => $pluginId,
            'table_prefix' => 'plugin_'.str_replace('-', '_', $pluginId).'_',
            'tables' => [],
            'directories' => [],
            'files' => [],
            'default_cleanup_policy' => 'preserve',
        ],
    ];

    $sideEffect = $withSideEffect
        ? "\nfile_put_contents(".var_export($paths['sentinel'], true).", 'executed');\n"
        : "\n";

    $pluginSource = <<<PHP
<?php

namespace AppLocalPlugins\\{$classSegment};

use App\\Plugins\\Contracts\\PluginInterface;
use App\\Plugins\\Support\\PluginActionResult;
use App\\Plugins\\Support\\PluginExecutionContext;{$sideEffect}
class Plugin implements PluginInterface
{
    public function runAction(string \$action, array \$payload, PluginExecutionContext \$context): PluginActionResult
    {
        return PluginActionResult::success('Fixture plugin action completed.', [
            'action' => \$action,
        ]);
    }
}
PHP;

    File::put(
        $paths['source'].'/plugin.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
    );
    File::put($paths['source'].'/Plugin.php', $pluginSource);

    return $paths;
}

function createZipArchiveForTests(string $sourcePath, string $archivePath, array $extraEntries = []): void
{
    File::delete($archivePath);
    File::ensureDirectoryExists(dirname($archivePath));

    $zip = new ZipArchive;
    $opened = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) {
        throw new RuntimeException("Unable to create zip archive [{$archivePath}].");
    }

    $baseLength = strlen(rtrim($sourcePath, DIRECTORY_SEPARATOR)) + 1;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourcePath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $file) {
        $localName = substr($file->getPathname(), $baseLength);

        if ($file->isDir()) {
            $zip->addEmptyDir($localName);
            continue;
        }

        $zip->addFile($file->getPathname(), $localName);
    }

    foreach ($extraEntries as $localName => $contents) {
        $zip->addFromString($localName, $contents);
    }

    $zip->close();
}

function cleanupReviewFixturePlugin(string $pluginId): void
{
    $paths = pluginReviewFixturePaths($pluginId);

    File::deleteDirectory($paths['source']);
    File::delete($paths['archive']);
    File::delete($paths['sentinel']);
}

it('discovers the bundled epg repair plugin as a valid local plugin', function () {
    $plugins = app(PluginManager::class)->discover();

    expect(collect($plugins)->pluck('plugin_id'))->toContain('epg-repair');

    $plugin = ExtensionPlugin::query()->where('plugin_id', 'epg-repair')->first();

    expect($plugin)->not->toBeNull();
    expect($plugin->validation_status)->toBe('valid');
    expect($plugin->available)->toBeTrue();
    expect($plugin->trust_state)->toBe('pending_review');
    expect($plugin->integrity_status)->toBe('unknown');
    expect($plugin->class_name)->toBe('AppLocalPlugins\\EpgRepair\\Plugin');
    expect($plugin->capabilities)->toContain('epg_repair');
    expect($plugin->permissions)->toContain('queue_jobs');
    expect($plugin->actions)->toBeArray();
});

it('validates a discovered plugin from the registry', function () {
    $plugin = collect(app(PluginManager::class)->discover())
        ->firstWhere('plugin_id', 'epg-repair');

    expect($plugin)->not->toBeNull();

    $validated = app(PluginManager::class)->validate($plugin);

    expect($validated->validation_status)->toBe('valid');
    expect($validated->validation_errors)->toBe([]);
});

it('rejects top-level executable plugin php while staging a directory review', function () {
    $pluginId = 'review-safety-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId, withSideEffect: true);
    $className = pluginReviewFixtureClassName($pluginId);

    try {
        $review = app(PluginManager::class)->stageDirectoryReview($paths['source']);

        expect($review->validation_status)->toBe('invalid');
        expect(File::exists($paths['sentinel']))->toBeFalse();
        expect(class_exists($className, false))->toBeFalse();
        expect($review->validation_errors)->not->toBeEmpty();
        expect(collect($review->validation_errors)->join(' '))->toContain('top-level executable code');
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('rejects top-level executable plugin php while staging an archive review', function () {
    $pluginId = 'archive-safety-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId, withSideEffect: true);
    createZipArchiveForTests($paths['source'], $paths['archive']);
    $className = pluginReviewFixtureClassName($pluginId);

    try {
        $review = app(PluginManager::class)->stageArchiveReview($paths['archive']);

        expect($review->validation_status)->toBe('invalid');
        expect(File::exists($paths['sentinel']))->toBeFalse();
        expect(class_exists($className, false))->toBeFalse();
        expect($review->validation_errors)->not->toBeEmpty();
        expect(collect($review->validation_errors)->join(' '))->toContain('top-level executable code');
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('rejects archive entries that try to escape the staging root', function () {
    $pluginId = 'archive-escape-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId);
    createZipArchiveForTests($paths['source'], $paths['archive'], [
        '../escape.txt' => 'nope',
    ]);

    try {
        expect(fn () => app(PluginManager::class)->stageArchiveReview($paths['archive']))
            ->toThrow(RuntimeException::class, 'unsafe path entry');
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('requires admin trust before a plugin becomes runnable', function () {
    $pluginManager = app(PluginManager::class);
    $plugin = collect($pluginManager->discover())
        ->firstWhere('plugin_id', 'epg-repair');

    expect($plugin)->not->toBeNull();
    expect($plugin->isTrusted())->toBeFalse();
    expect($plugin->hasVerifiedIntegrity())->toBeFalse();

    $review = approvePluginReviewForTests(base_path('plugins/epg-repair'), false);
    expect($review->scan_status)->toBe('clean');

    $trusted = $pluginManager->trust($pluginManager->findPluginById('epg-repair')->fresh());

    expect($trusted->isTrusted())->toBeTrue();
    expect($trusted->hasVerifiedIntegrity())->toBeTrue();
    expect($trusted->trusted_hashes)->toMatchArray([
        'manifest_hash' => $trusted->manifest_hash,
        'entrypoint_hash' => $trusted->entrypoint_hash,
        'plugin_hash' => $trusted->plugin_hash,
    ]);
});

it('downgrades trust when a trusted plugin file changes', function () {
    $pluginManager = app(PluginManager::class);
    approvePluginReviewForTests(base_path('plugins/epg-repair'), true);
    $plugin = $pluginManager->findPluginById('epg-repair');

    $manifestPath = base_path('plugins/epg-repair/plugin.json');
    $originalManifest = File::get($manifestPath);
    $decoded = json_decode($originalManifest, true, flags: JSON_THROW_ON_ERROR);
    $decoded['description'] = 'Tampered during test '.Str::random(6);

    File::put($manifestPath, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    try {
        $plugin = $pluginManager->verifyIntegrity($plugin->fresh());

        expect($plugin->trust_state)->toBe('pending_review');
        expect($plugin->integrity_status)->toBe('changed');
        expect($plugin->enabled)->toBeFalse();
    } finally {
        File::put($manifestPath, $originalManifest);
        $pluginManager->validate($plugin->fresh());
    }
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

it('rejects manual plugin actions against resources owned by another user', function () {
    $pluginManager = app(PluginManager::class);
    $plugin = discoverPluginForTests(true);

    $owner = User::factory()->create(['permissions' => ['use_tools']]);
    $attacker = User::factory()->create(['permissions' => ['use_tools']]);

    $playlist = Playlist::create([
        'name' => 'Owned Playlist',
        'uuid' => (string) Str::uuid(),
        'url' => 'http://example.test/owned.m3u',
        'status' => Status::Completed,
        'prefix' => 'owned',
        'channels' => 0,
        'synced' => now(),
        'id_channel_by' => 'stream_id',
        'user_id' => $owner->id,
    ]);

    $epg = Epg::create([
        'name' => 'Owned EPG',
        'url' => 'http://example.test/owned.xml',
        'user_id' => $owner->id,
        'status' => Status::Completed,
    ]);

    $run = $pluginManager->executeAction($plugin->fresh(), 'scan', [
        'playlist_id' => $playlist->id,
        'epg_id' => $epg->id,
        'hours_ahead' => 12,
        'confidence_threshold' => 0.6,
    ], [
        'trigger' => 'manual',
        'dry_run' => true,
        'user_id' => $attacker->id,
    ]);

    expect($run->status)->toBe('failed');
    expect($run->summary)->toContain('do not have access');
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
    expect(Schema::hasTable('plugin_epg_repair_scan_candidates'))->toBeFalse();

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

    $rediscovered = collect($pluginManager->discover())
        ->firstWhere('plugin_id', $pluginId);

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

it('supports trust, block, and integrity verification commands from the host', function () {
    $this->artisan('plugins:discover')->assertSuccessful();
    $review = app(PluginManager::class)->stageDirectoryReview(base_path('plugins/epg-repair'));
    app(PluginManager::class)->scanInstallReview($review);
    app(PluginManager::class)->approveInstallReview($review, false);

    $this->artisan('plugins:verify-integrity', [
        'pluginId' => 'epg-repair',
    ])->assertSuccessful()
        ->expectsOutputToContain('integrity=unknown');

    $this->artisan('plugins:trust', [
        'pluginId' => 'epg-repair',
    ])->assertSuccessful()
        ->expectsOutputToContain('now trusted');

    $plugin = app(PluginManager::class)->findPluginById('epg-repair');

    expect($plugin?->trust_state)->toBe('trusted');
    expect($plugin?->integrity_status)->toBe('verified');

    $this->artisan('plugins:block', [
        'pluginId' => 'epg-repair',
    ])->assertSuccessful()
        ->expectsOutputToContain('now blocked');

    expect(app(PluginManager::class)->findPluginById('epg-repair')?->trust_state)->toBe('blocked');
});

it('supports the reviewed install command flow for local plugin directories', function () {
    $this->artisan('plugins:stage-directory', [
        'path' => base_path('plugins/epg-repair'),
    ])->assertSuccessful()
        ->expectsOutputToContain('Created install review');

    $review = PluginInstallReview::query()->latest('id')->first();

    expect($review)->not->toBeNull();
    expect($review?->plugin_id)->toBe('epg-repair');

    $this->artisan('plugins:scan-install', [
        'reviewId' => $review->id,
    ])->assertSuccessful()
        ->expectsOutputToContain('scan status: clean');

    $this->artisan('plugins:approve-install', [
        'reviewId' => $review->id,
        '--trust' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('installed plugin [epg-repair]');

    $plugin = app(PluginManager::class)->findPluginById('epg-repair');

    expect($plugin?->trust_state)->toBe('trusted');
    expect($plugin?->integrity_status)->toBe('verified');
});

it('reports plugin registry health through the doctor command', function () {
    Storage::fake('local');

    $plugin = collect(app(PluginManager::class)->discover())
        ->firstWhere('plugin_id', 'epg-repair');
    $plugin = app(PluginManager::class)->reinstall($plugin->fresh());
    app(PluginManager::class)->approveInstallReview(
        app(PluginManager::class)->scanInstallReview(
            app(PluginManager::class)->stageDirectoryReview(base_path('plugins/epg-repair'))
        ),
        true,
    );

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
