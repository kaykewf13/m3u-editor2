<?php

namespace App\Plugins;

use App\Models\ExtensionPlugin;
use App\Models\ExtensionPluginRun;
use App\Models\User;
use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Contracts\LifecyclePluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Contracts\ScheduledPluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use App\Plugins\Support\PluginUninstallContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PluginManager
{
    public function __construct(
        private readonly PluginValidator $validator,
        private readonly PluginSchemaMapper $schemaMapper,
        private readonly PluginManifestLoader $manifestLoader,
        private readonly PluginIntegrityService $integrityService,
        private readonly PluginSchemaManager $schemaManager,
    ) {}

    public function discover(): array
    {
        $discovered = [];
        $seenPaths = [];

        foreach ($this->pluginPaths() as $pluginPath) {
            $result = $this->validator->validatePath($pluginPath);
            $manifest = $result->manifest;
            $pluginId = $result->pluginId ?? basename($pluginPath);

            $record = ExtensionPlugin::query()->where('plugin_id', $pluginId)->first()
                ?? new ExtensionPlugin(['plugin_id' => $pluginId]);
            $securityState = $this->determineSecurityState($record, $result, file_exists($pluginPath));
            $attributes = [
                'name' => $manifest?->name ?? Arr::get($result->manifestData, 'name', $pluginId),
                'version' => $manifest?->version,
                'api_version' => $manifest?->apiVersion ?? Arr::get($result->manifestData, 'api_version'),
                'description' => $manifest?->description ?? Arr::get($result->manifestData, 'description'),
                'entrypoint' => $manifest?->entrypoint ?? Arr::get($result->manifestData, 'entrypoint'),
                'class_name' => $manifest?->className ?? Arr::get($result->manifestData, 'class'),
                'capabilities' => $manifest?->capabilities ?? Arr::get($result->manifestData, 'capabilities', []),
                'hooks' => $manifest?->hooks ?? Arr::get($result->manifestData, 'hooks', []),
                'actions' => $manifest?->actions ?? Arr::get($result->manifestData, 'actions', []),
                'permissions' => $manifest?->permissions ?? Arr::get($result->manifestData, 'permissions', []),
                'schema_definition' => $manifest?->schema ?? Arr::get($result->manifestData, 'schema', []),
                'settings_schema' => $manifest?->settings ?? Arr::get($result->manifestData, 'settings', []),
                'data_ownership' => $manifest?->dataOwnership ?? Arr::get($result->manifestData, 'data_ownership', []),
                'path' => $pluginPath,
                'source_type' => 'local',
                'available' => true,
                'validation_status' => $result->valid ? 'valid' : 'invalid',
                'validation_errors' => $result->errors,
                'manifest_hash' => $result->hashes['manifest_hash'] ?? null,
                'entrypoint_hash' => $result->hashes['entrypoint_hash'] ?? null,
                'plugin_hash' => $result->hashes['plugin_hash'] ?? null,
                'trust_state' => $securityState['trust_state'],
                'trust_reason' => $securityState['trust_reason'],
                'integrity_status' => $securityState['integrity_status'],
                'integrity_verified_at' => $securityState['integrity_verified_at'],
                'enabled' => $securityState['enabled'],
                'last_discovered_at' => now(),
                'last_validated_at' => now(),
            ];

            $record = ExtensionPlugin::query()->updateOrCreate(
                ['plugin_id' => $pluginId],
                $attributes,
            );

            $seenPaths[] = $pluginPath;
            $discovered[] = $record->fresh();
        }

        if ($seenPaths !== []) {
            $missingPlugins = ExtensionPlugin::query()
                ->whereNotIn('path', $seenPaths)
                ->get();

            foreach ($missingPlugins as $missingPlugin) {
                $trustState = $missingPlugin->isBlocked() ? 'blocked' : 'pending_review';
                $missingPlugin->update([
                    'available' => false,
                    'enabled' => false,
                    'integrity_status' => 'missing',
                    'trust_state' => $trustState,
                    'trust_reason' => 'Plugin files are missing from disk and require operator review.',
                ]);
            }
        }

        return $discovered;
    }

    public function validate(ExtensionPlugin $plugin): ExtensionPlugin
    {
        $result = $this->validator->validatePath((string) $plugin->path);
        $securityState = $this->determineSecurityState($plugin, $result, file_exists((string) $plugin->path));

        $plugin->update([
            'name' => $result->manifest?->name ?? $plugin->name,
            'version' => $result->manifest?->version ?? $plugin->version,
            'api_version' => $result->manifest?->apiVersion ?? $plugin->api_version,
            'description' => $result->manifest?->description ?? $plugin->description,
            'entrypoint' => $result->manifest?->entrypoint ?? $plugin->entrypoint,
            'class_name' => $result->manifest?->className ?? $plugin->class_name,
            'capabilities' => $result->manifest?->capabilities ?? $plugin->capabilities,
            'hooks' => $result->manifest?->hooks ?? $plugin->hooks,
            'actions' => $result->manifest?->actions ?? $plugin->actions,
            'permissions' => $result->manifest?->permissions ?? $plugin->permissions,
            'schema_definition' => $result->manifest?->schema ?? $plugin->schema_definition,
            'settings_schema' => $result->manifest?->settings ?? $plugin->settings_schema,
            'data_ownership' => $result->manifest?->dataOwnership ?? $plugin->data_ownership,
            'validation_status' => $result->valid ? 'valid' : 'invalid',
            'validation_errors' => $result->errors,
            'manifest_hash' => $result->hashes['manifest_hash'] ?? null,
            'entrypoint_hash' => $result->hashes['entrypoint_hash'] ?? null,
            'plugin_hash' => $result->hashes['plugin_hash'] ?? null,
            'trust_state' => $securityState['trust_state'],
            'trust_reason' => $securityState['trust_reason'],
            'integrity_status' => $securityState['integrity_status'],
            'integrity_verified_at' => $securityState['integrity_verified_at'],
            'enabled' => $securityState['enabled'],
            'last_validated_at' => now(),
            'available' => file_exists((string) $plugin->path),
        ]);

        return $plugin->fresh();
    }

    public function findPluginById(string $pluginId): ?ExtensionPlugin
    {
        return ExtensionPlugin::query()
            ->where('plugin_id', $pluginId)
            ->first();
    }

    public function resolvedSettings(ExtensionPlugin $plugin): array
    {
        return $this->schemaMapper->defaultsForFields(
            $plugin->settings_schema ?? [],
            $plugin->settings ?? [],
        );
    }

    public function updateSettings(ExtensionPlugin $plugin, array $settings): ExtensionPlugin
    {
        Validator::make(
            ['settings' => $settings],
            $this->schemaMapper->settingsRules($plugin),
        )->validate();
        $this->assertOwnedModelSelections(
            $plugin->settings_schema ?? [],
            $settings,
            auth()->user(),
        );

        $plugin->update([
            'settings' => $this->resolvedSettings($plugin) + $settings,
        ]);

        return $plugin->fresh();
    }

    public function executeAction(
        ExtensionPlugin $plugin,
        string $action,
        array $payload = [],
        array $options = [],
    ): ExtensionPluginRun {
        $this->recoverStaleRuns();
        $actingUser = isset($options['user_id']) ? User::find($options['user_id']) : null;

        $run = $this->prepareRun($plugin, [
            'trigger' => $options['trigger'] ?? 'manual',
            'invocation_type' => 'action',
            'action' => $action,
            'payload' => $payload,
            'dry_run' => (bool) ($options['dry_run'] ?? false),
            'user_id' => $options['user_id'] ?? null,
        ], $options);

        try {
            Validator::make($payload, $this->schemaMapper->actionRules($plugin, $action))->validate();
            $this->assertOwnedModelSelections(
                $plugin->getActionDefinition($action)['fields'] ?? [],
                $payload,
                $actingUser,
            );

            $instance = $this->instantiate($plugin);
            $context = new PluginExecutionContext(
                plugin: $plugin,
                run: $run,
                trigger: (string) ($options['trigger'] ?? 'manual'),
                dryRun: (bool) ($options['dry_run'] ?? false),
                hook: null,
                user: $actingUser,
                settings: $this->resolvedSettings($plugin),
            );

            $result = $instance->runAction($action, $payload, $context);

            return $this->finishRun($run, $result);
        } catch (Throwable $exception) {
            return $this->failRun($run, $exception->getMessage());
        }
    }

    public function executeHook(
        ExtensionPlugin $plugin,
        string $hook,
        array $payload = [],
        array $options = [],
    ): ExtensionPluginRun {
        $this->recoverStaleRuns();

        $run = $this->prepareRun($plugin, [
            'trigger' => $options['trigger'] ?? 'hook',
            'invocation_type' => 'hook',
            'hook' => $hook,
            'payload' => $payload,
            'dry_run' => (bool) ($options['dry_run'] ?? true),
            'user_id' => $options['user_id'] ?? null,
        ], $options);

        try {
            $instance = $this->instantiate($plugin);
            if (! $instance instanceof HookablePluginInterface) {
                throw new RuntimeException("Plugin [{$plugin->plugin_id}] does not implement hook handling.");
            }

            $context = new PluginExecutionContext(
                plugin: $plugin,
                run: $run,
                trigger: (string) ($options['trigger'] ?? 'hook'),
                dryRun: (bool) ($options['dry_run'] ?? true),
                hook: $hook,
                user: isset($options['user_id']) ? User::find($options['user_id']) : null,
                settings: $this->resolvedSettings($plugin),
            );

            $result = $instance->runHook($hook, $payload, $context);

            return $this->finishRun($run, $result);
        } catch (Throwable $exception) {
            return $this->failRun($run, $exception->getMessage());
        }
    }

    public function scheduledInvocations(ExtensionPlugin $plugin, CarbonInterface $now): array
    {
        $instance = $this->instantiate($plugin);
        if (! $instance instanceof ScheduledPluginInterface) {
            return [];
        }

        return $instance->scheduledActions($now, $this->resolvedSettings($plugin));
    }

    public function enabledPluginsForHook(string $hook)
    {
        return ExtensionPlugin::query()
            ->where('enabled', true)
            ->where('available', true)
            ->where('installation_status', 'installed')
            ->where('validation_status', 'valid')
            ->where('trust_state', 'trusted')
            ->where('integrity_status', 'verified')
            ->get()
            ->filter(fn (ExtensionPlugin $plugin) => in_array($hook, $plugin->hooks ?? [], true))
            ->values();
    }

    public function instantiate(ExtensionPlugin $plugin): PluginInterface
    {
        $plugin = $this->validate($plugin);
        $this->assertPluginLoadable($plugin, requireEnabled: false);
        $this->assertPluginRunnable($plugin);

        $entrypoint = rtrim((string) $plugin->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$plugin->entrypoint;
        require_once $entrypoint;

        $instance = app($plugin->class_name);
        if (! $instance instanceof PluginInterface) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] did not resolve to a valid plugin instance.");
        }

        return $instance;
    }

    public function trust(ExtensionPlugin $plugin, ?int $userId = null, ?string $reason = null): ExtensionPlugin
    {
        $plugin = $this->validate($plugin);
        $this->assertPluginLoadable($plugin, requireEnabled: false);

        if ($plugin->validation_status !== 'valid') {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] must validate successfully before it can be trusted.");
        }

        if (! $plugin->manifest_hash || ! $plugin->entrypoint_hash || ! $plugin->plugin_hash) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] is missing integrity hashes and cannot be trusted.");
        }

        $schema = $plugin->schema_definition ?? [];
        if (($schema['tables'] ?? []) !== []) {
            $this->schemaManager->apply($schema);
        }

        $plugin->update([
            'trust_state' => 'trusted',
            'trust_reason' => $reason ?: 'Plugin reviewed and trusted by an administrator.',
            'trusted_at' => now(),
            'trusted_by_user_id' => $userId,
            'blocked_at' => null,
            'blocked_by_user_id' => null,
            'integrity_status' => 'verified',
            'integrity_verified_at' => now(),
            'trusted_hashes' => $this->currentHashSnapshot($plugin),
        ]);

        return $plugin->fresh();
    }

    public function block(ExtensionPlugin $plugin, ?string $reason = null, ?int $userId = null): ExtensionPlugin
    {
        $plugin->update([
            'enabled' => false,
            'trust_state' => 'blocked',
            'trust_reason' => $reason ?: 'Plugin blocked by an administrator.',
            'blocked_at' => now(),
            'blocked_by_user_id' => $userId,
        ]);

        return $plugin->fresh();
    }

    public function verifyIntegrity(ExtensionPlugin $plugin): ExtensionPlugin
    {
        return $this->validate($plugin);
    }

    public function uninstall(ExtensionPlugin $plugin, string $cleanupMode = 'preserve', ?int $userId = null): ExtensionPlugin
    {
        if (! in_array($cleanupMode, config('plugins.cleanup_modes', []), true)) {
            throw new RuntimeException("Unsupported cleanup mode [{$cleanupMode}]");
        }

        $activeRuns = $plugin->runs()
            ->where('status', 'running')
            ->get();

        foreach ($activeRuns as $run) {
            $this->requestCancellation($run, $userId);
        }

        if ($cleanupMode === 'purge' && $activeRuns->isNotEmpty()) {
            throw new RuntimeException('Active runs were asked to stop. Wait for them to finish, then retry uninstall with data purge.');
        }

        $ownership = $this->ownershipForPlugin($plugin);

        if ($cleanupMode === 'purge') {
            $this->runPluginUninstallHook($plugin, $cleanupMode, $ownership, $userId);
            $this->purgeOwnedData($ownership);
        }

        $plugin->update([
            'enabled' => false,
            'installation_status' => 'uninstalled',
            'last_cleanup_mode' => $cleanupMode,
            'uninstalled_at' => now(),
            'data_ownership' => $ownership,
        ]);

        return $plugin->fresh();
    }

    public function forgetRegistryRecord(ExtensionPlugin $plugin): void
    {
        if ($plugin->hasActiveRuns()) {
            throw new RuntimeException('Cannot forget a plugin registry record while it still has active runs.');
        }

        $plugin->delete();
    }

    public function reinstall(ExtensionPlugin $plugin): ExtensionPlugin
    {
        $plugin->update([
            'installation_status' => 'installed',
            'last_cleanup_mode' => null,
            'uninstalled_at' => null,
        ]);
        $plugin = $this->validate($plugin->fresh());

        if ($plugin->isTrusted() && $plugin->hasVerifiedIntegrity() && ($plugin->schema_definition['tables'] ?? []) !== []) {
            $this->schemaManager->apply($plugin->schema_definition ?? []);
        }

        return $plugin->fresh();
    }

    public function requestCancellation(ExtensionPluginRun $run, ?int $userId = null): ExtensionPluginRun
    {
        if ($run->status !== 'running') {
            return $run->fresh();
        }

        $run->logs()->create([
            'level' => 'warning',
            'message' => 'Cancellation requested by operator.',
            'context' => [
                'user_id' => $userId,
            ],
        ]);

        $run->update([
            'cancel_requested' => true,
            'cancel_requested_at' => now(),
            'last_heartbeat_at' => now(),
            'progress_message' => 'Cancellation requested. Waiting for the worker to stop cleanly.',
        ]);

        return $run->fresh();
    }

    public function resumeRun(ExtensionPluginRun $run, ?int $userId = null): ExtensionPluginRun
    {
        $plugin = $run->plugin()->firstOrFail();

        if (! in_array($run->status, ['cancelled', 'stale', 'failed'], true)) {
            return $run->fresh();
        }

        dispatch(new \App\Jobs\ExecutePluginInvocation(
            pluginId: $plugin->id,
            invocationType: $run->invocation_type,
            name: $run->action ?? $run->hook ?? throw new RuntimeException('Run cannot be resumed without an action or hook name.'),
            payload: $run->payload ?? [],
            options: [
                'trigger' => $run->trigger,
                'dry_run' => $run->dry_run,
                'user_id' => $userId ?? $run->user_id,
                'existing_run_id' => $run->id,
                'resume' => true,
            ],
        ));

        return $run->fresh();
    }

    public function recoverStaleRuns(int $minutes = 15): int
    {
        $staleRuns = ExtensionPluginRun::query()
            ->where('status', 'running')
            ->where(function ($query) use ($minutes) {
                $query
                    ->where(function ($heartbeatQuery) use ($minutes) {
                        $heartbeatQuery
                            ->whereNotNull('last_heartbeat_at')
                            ->where('last_heartbeat_at', '<', now()->subMinutes($minutes));
                    })
                    ->orWhere(function ($legacyQuery) use ($minutes) {
                        $legacyQuery
                            ->whereNull('last_heartbeat_at')
                            ->whereNotNull('started_at')
                            ->where('started_at', '<', now()->subMinutes($minutes));
                    });
            })
            ->get();

        foreach ($staleRuns as $run) {
            $summary = $run->progress_message ?: $run->summary ?: 'Run lost its heartbeat and was marked stale.';

            $run->logs()->create([
                'level' => 'warning',
                'message' => 'Run heartbeat expired. Marking the run as stale so an operator can resume or rerun it.',
                'context' => [
                    'last_heartbeat_at' => optional($run->last_heartbeat_at)->toDateTimeString(),
                ],
            ]);

            $run->update([
                'status' => 'stale',
                'summary' => $summary,
                'stale_at' => now(),
                'finished_at' => $run->finished_at ?? now(),
                'result' => [
                    'status' => 'stale',
                    'success' => false,
                    'summary' => $summary,
                    'data' => [
                        'run_state' => $run->run_state ?? [],
                    ],
                ],
            ]);
        }

        return $staleRuns->count();
    }

    private function pluginPaths(): array
    {
        $paths = [];

        foreach (config('plugins.directories', []) as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $pluginPath) {
                if (file_exists($pluginPath.DIRECTORY_SEPARATOR.'plugin.json')) {
                    $paths[] = $pluginPath;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    public function registryDiagnostics(): array
    {
        $issues = [];
        $pluginPaths = collect($this->pluginPaths());
        $registryPlugins = ExtensionPlugin::query()->get();

        foreach ($registryPlugins as $plugin) {
            if ($plugin->enabled && ! $plugin->isInstalled()) {
                $issues[] = [
                    'plugin_id' => $plugin->plugin_id,
                    'level' => 'error',
                    'code' => 'enabled_uninstalled',
                    'message' => 'Plugin is enabled but marked uninstalled.',
                ];
            }

            if ($plugin->enabled && ! $plugin->available) {
                $issues[] = [
                    'plugin_id' => $plugin->plugin_id,
                    'level' => 'error',
                    'code' => 'enabled_missing_files',
                    'message' => 'Plugin is enabled but its files are not available on disk.',
                ];
            }

            if ($plugin->enabled && $plugin->validation_status !== 'valid') {
                $issues[] = [
                    'plugin_id' => $plugin->plugin_id,
                    'level' => 'warning',
                    'code' => 'enabled_invalid',
                    'message' => 'Plugin is enabled even though validation is not currently valid.',
                ];
            }

            if ($plugin->enabled && ! $plugin->isTrusted()) {
                $issues[] = [
                    'plugin_id' => $plugin->plugin_id,
                    'level' => 'error',
                    'code' => 'enabled_untrusted',
                    'message' => 'Plugin is enabled but has not been trusted by an administrator.',
                ];
            }

            if ($plugin->enabled && ! $plugin->hasVerifiedIntegrity()) {
                $issues[] = [
                    'plugin_id' => $plugin->plugin_id,
                    'level' => 'error',
                    'code' => 'enabled_integrity_unverified',
                    'message' => 'Plugin is enabled even though its integrity is not verified.',
                ];
            }

            if ($plugin->integrity_status === 'changed') {
                $issues[] = [
                    'plugin_id' => $plugin->plugin_id,
                    'level' => 'error',
                    'code' => 'plugin_files_changed',
                    'message' => 'Plugin files changed after trust and require a fresh review.',
                ];
            }

            if (($plugin->last_cleanup_mode ?? null) === 'purge') {
                $ownership = $plugin->data_ownership ?? [];

                foreach ($ownership['directories'] ?? [] as $directory) {
                    if (Storage::disk('local')->exists($directory)) {
                        $issues[] = [
                            'plugin_id' => $plugin->plugin_id,
                            'level' => 'warning',
                            'code' => 'purged_directory_still_exists',
                            'message' => "Declared plugin-owned directory [{$directory}] still exists after a purge uninstall.",
                        ];
                    }
                }

                foreach ($ownership['files'] ?? [] as $file) {
                    if (Storage::disk('local')->exists($file)) {
                        $issues[] = [
                            'plugin_id' => $plugin->plugin_id,
                            'level' => 'warning',
                            'code' => 'purged_file_still_exists',
                            'message' => "Declared plugin-owned file [{$file}] still exists after a purge uninstall.",
                        ];
                    }
                }

                foreach ($ownership['tables'] ?? [] as $table) {
                    if (Schema::hasTable($table)) {
                        $issues[] = [
                            'plugin_id' => $plugin->plugin_id,
                            'level' => 'warning',
                            'code' => 'purged_table_still_exists',
                            'message' => "Declared plugin-owned table [{$table}] still exists after a purge uninstall.",
                        ];
                    }
                }
            }

            if ($plugin->isInstalled() || ($plugin->last_cleanup_mode ?? null) !== 'purge') {
                foreach ($this->schemaManager->diagnostics($plugin->plugin_id, $plugin->schema_definition ?? []) as $diagnostic) {
                    $issues[] = [
                        'plugin_id' => $plugin->plugin_id,
                        ...$diagnostic,
                    ];
                }
            }
        }

        foreach ($pluginPaths as $pluginPath) {
            if (! $registryPlugins->contains(fn (ExtensionPlugin $plugin) => $plugin->path === $pluginPath)) {
                $issues[] = [
                    'plugin_id' => basename($pluginPath),
                    'level' => 'info',
                    'code' => 'missing_registry_record',
                    'message' => 'Local plugin exists on disk but has not been discovered into the registry yet.',
                ];
            }
        }

        return $issues;
    }

    private function ownershipForPlugin(ExtensionPlugin $plugin): array
    {
        try {
            if ($plugin->path && file_exists((string) $plugin->path.DIRECTORY_SEPARATOR.'plugin.json')) {
                return $this->manifestLoader->load((string) $plugin->path)->dataOwnership;
            }
        } catch (Throwable) {
            // Fall back to the last persisted ownership snapshot so uninstall can still proceed.
        }

        return $plugin->data_ownership ?? [
            'plugin_id' => $plugin->plugin_id,
            'table_prefix' => 'plugin_'.Str::of($plugin->plugin_id)->replace('-', '_')->lower()->value().'_',
            'tables' => [],
            'directories' => [],
            'files' => [],
            'default_cleanup_policy' => 'preserve',
        ];
    }

    private function runPluginUninstallHook(ExtensionPlugin $plugin, string $cleanupMode, array $ownership, ?int $userId): void
    {
        if (! $plugin->path || ! file_exists(rtrim((string) $plugin->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.(string) $plugin->entrypoint)) {
            return;
        }

        if (! $plugin->isTrusted() || ! $plugin->hasVerifiedIntegrity() || $plugin->validation_status !== 'valid') {
            return;
        }

        require_once rtrim((string) $plugin->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$plugin->entrypoint;

        $instance = app($plugin->class_name);
        if (! $instance instanceof LifecyclePluginInterface) {
            return;
        }

        $instance->uninstall(new PluginUninstallContext(
            plugin: $plugin->fresh(),
            cleanupMode: $cleanupMode,
            dataOwnership: $ownership,
            user: $userId ? User::find($userId) : null,
        ));
    }

    private function purgeOwnedData(array $ownership): void
    {
        $this->schemaManager->purge(['tables' => collect($ownership['tables'] ?? [])
            ->map(fn (string $table) => ['name' => $table, 'columns' => [['type' => 'id']]])
            ->all()]);

        foreach ($ownership['files'] ?? [] as $file) {
            Storage::disk('local')->delete($file);
        }

        foreach ($ownership['directories'] ?? [] as $directory) {
            Storage::disk('local')->deleteDirectory($directory);
        }
    }

    private function prepareRun(ExtensionPlugin $plugin, array $attributes, array $options = []): ExtensionPluginRun
    {
        $existingRunId = $options['existing_run_id'] ?? null;

        if (! $existingRunId) {
            return $this->startRun($plugin, $attributes);
        }

        $run = ExtensionPluginRun::query()
            ->where('extension_plugin_id', $plugin->id)
            ->findOrFail($existingRunId);

        $resumeMessage = ($options['resume'] ?? false)
            ? 'Run resumed from its last saved checkpoint.'
            : ($run->summary ?: 'Run restarted.');

        $run->logs()->create([
            'level' => 'info',
            'message' => $resumeMessage,
            'context' => [
                'resume' => (bool) ($options['resume'] ?? false),
            ],
        ]);

        $run->update([
            ...$attributes,
            'status' => 'running',
            'summary' => $resumeMessage,
            'result' => null,
            'progress_message' => $resumeMessage,
            'cancel_requested' => false,
            'cancel_requested_at' => null,
            'cancelled_at' => null,
            'stale_at' => null,
            'finished_at' => null,
            'last_heartbeat_at' => now(),
            'started_at' => $run->started_at ?? now(),
        ]);

        return $run->fresh();
    }

    private function startRun(ExtensionPlugin $plugin, array $attributes): ExtensionPluginRun
    {
        return $plugin->runs()->create([
            ...$attributes,
            'status' => 'running',
            'progress' => 0,
            'progress_message' => 'Run queued and waiting for the worker to start.',
            'last_heartbeat_at' => now(),
            'started_at' => now(),
        ]);
    }

    private function finishRun(ExtensionPluginRun $run, PluginActionResult $result): ExtensionPluginRun
    {
        $run->logs()->create([
            'level' => $result->success ? 'info' : 'error',
            'message' => $result->summary,
            'context' => [
                'result' => $result->data,
            ],
        ]);

        $run->update([
            'status' => $result->status,
            'result' => $result->toArray(),
            'summary' => $result->summary,
            'progress' => (int) data_get($result->data, 'progress', $result->status === 'completed' ? 100 : $run->progress),
            'progress_message' => $result->summary,
            'last_heartbeat_at' => now(),
            'cancel_requested' => false,
            'cancel_requested_at' => null,
            'cancelled_at' => $result->status === 'cancelled' ? now() : null,
            'run_state' => in_array($result->status, ['cancelled', 'stale'], true) ? $run->run_state : null,
            'finished_at' => now(),
        ]);

        return $run->fresh();
    }

    private function failRun(ExtensionPluginRun $run, string $message): ExtensionPluginRun
    {
        $run->logs()->create([
            'level' => 'error',
            'message' => $message,
            'context' => [],
        ]);

        $run->update([
            'status' => 'failed',
            'summary' => $message,
            'progress_message' => $message,
            'last_heartbeat_at' => now(),
            'result' => [
                'status' => 'failed',
                'success' => false,
                'summary' => $message,
                'data' => [],
            ],
            'finished_at' => now(),
        ]);

        return $run->fresh();
    }

    private function determineSecurityState(ExtensionPlugin $existing, \App\Plugins\Support\PluginValidationResult $result, bool $available): array
    {
        $currentHashes = $this->normalizeHashSnapshot($result->hashes);
        $trustedHashes = $this->normalizeHashSnapshot($existing->trusted_hashes ?? []);
        $trustState = $existing->trust_state ?: 'pending_review';
        $trustReason = $existing->trust_reason;
        $integrityStatus = 'unknown';
        $integrityVerifiedAt = null;
        $enabled = (bool) $existing->enabled;

        if (! $available || $currentHashes === []) {
            $integrityStatus = 'missing';
            $enabled = false;

            if ($trustState !== 'blocked') {
                $trustState = 'pending_review';
                $trustReason = 'Plugin files are missing from disk and require review.';
            }

            return [
                'trust_state' => $trustState,
                'trust_reason' => $trustReason,
                'integrity_status' => $integrityStatus,
                'integrity_verified_at' => $integrityVerifiedAt,
                'enabled' => $enabled,
            ];
        }

        if ($trustedHashes === []) {
            $integrityStatus = 'unknown';
            $trustState = $trustState === 'blocked' ? 'blocked' : 'pending_review';
            $trustReason ??= 'Plugin discovered and awaiting admin review.';
        } elseif ($trustedHashes === $currentHashes) {
            $integrityStatus = 'verified';
            $integrityVerifiedAt = now();
        } else {
            $integrityStatus = 'changed';
            $enabled = false;

            if ($trustState !== 'blocked') {
                $trustState = 'pending_review';
                $trustReason = 'Plugin files changed since they were last trusted.';
            }
        }

        if (! $result->valid) {
            $enabled = false;

            if ($trustState === 'trusted') {
                $trustState = 'pending_review';
                $trustReason = 'Plugin validation no longer passes and requires review.';
            }
        }

        if ($trustState !== 'trusted' || $integrityStatus !== 'verified') {
            $enabled = false;
        }

        return [
            'trust_state' => $trustState,
            'trust_reason' => $trustReason,
            'integrity_status' => $integrityStatus,
            'integrity_verified_at' => $integrityVerifiedAt,
            'enabled' => $enabled,
        ];
    }

    private function normalizeHashSnapshot(array $hashes): array
    {
        return array_filter([
            'manifest_hash' => $hashes['manifest_hash'] ?? null,
            'entrypoint_hash' => $hashes['entrypoint_hash'] ?? null,
            'plugin_hash' => $hashes['plugin_hash'] ?? null,
        ]);
    }

    private function currentHashSnapshot(ExtensionPlugin $plugin): array
    {
        return $this->normalizeHashSnapshot([
            'manifest_hash' => $plugin->manifest_hash,
            'entrypoint_hash' => $plugin->entrypoint_hash,
            'plugin_hash' => $plugin->plugin_hash,
        ]);
    }

    private function assertPluginLoadable(ExtensionPlugin $plugin, bool $requireEnabled): void
    {
        if (! $plugin->isInstalled()) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] has been uninstalled and must be reinstalled before it can run.");
        }

        if (! $plugin->available) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] is missing from disk.");
        }

        if ($plugin->validation_status !== 'valid') {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] is not valid.");
        }

        if ($requireEnabled && ! $plugin->enabled) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] is disabled.");
        }
    }

    private function assertPluginRunnable(ExtensionPlugin $plugin): void
    {
        if (! $plugin->enabled) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] is disabled.");
        }

        if ($plugin->isBlocked()) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] has been blocked by an administrator.");
        }

        if (! $plugin->isTrusted()) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] must be trusted by an administrator before it can run.");
        }

        if (! $plugin->hasVerifiedIntegrity()) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] integrity is not verified.");
        }
    }

    private function assertOwnedModelSelections(array $fields, array $payload, ?User $user): void
    {
        if (! $user || $user->isAdmin()) {
            return;
        }

        foreach ($fields as $field) {
            if (($field['type'] ?? null) !== 'model_select' || ($field['scope'] ?? null) !== 'owned') {
                continue;
            }

            $fieldId = $field['id'] ?? null;
            $modelClass = $field['model'] ?? null;
            $value = $fieldId ? ($payload[$fieldId] ?? null) : null;

            if (! $fieldId || ! $value || ! is_string($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
                continue;
            }

            $model = $modelClass::query()->find($value);
            if (! $model) {
                continue;
            }

            if (! array_key_exists('user_id', $model->getAttributes())) {
                throw new RuntimeException("Owned plugin field [{$fieldId}] cannot be enforced because [{$modelClass}] does not expose a user_id column.");
            }

            if ((int) $model->getAttribute('user_id') !== (int) $user->id) {
                throw new RuntimeException("You do not have access to the selected resource for [{$fieldId}].");
            }
        }
    }
}
