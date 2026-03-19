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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
    ) {}

    public function discover(): array
    {
        $discovered = [];
        $seenPaths = [];

        foreach ($this->pluginPaths() as $pluginPath) {
            $result = $this->validator->validatePath($pluginPath);
            $manifest = $result->manifest;
            $pluginId = $result->pluginId ?? basename($pluginPath);

            $record = ExtensionPlugin::query()->firstOrNew(['plugin_id' => $pluginId]);
            $record->fill([
                'name' => $manifest?->name ?? Arr::get($result->manifestData, 'name', $pluginId),
                'version' => $manifest?->version,
                'api_version' => $manifest?->apiVersion ?? Arr::get($result->manifestData, 'api_version'),
                'description' => $manifest?->description ?? Arr::get($result->manifestData, 'description'),
                'entrypoint' => $manifest?->entrypoint ?? Arr::get($result->manifestData, 'entrypoint'),
                'class_name' => $manifest?->className ?? Arr::get($result->manifestData, 'class'),
                'capabilities' => $manifest?->capabilities ?? Arr::get($result->manifestData, 'capabilities', []),
                'hooks' => $manifest?->hooks ?? Arr::get($result->manifestData, 'hooks', []),
                'actions' => $manifest?->actions ?? Arr::get($result->manifestData, 'actions', []),
                'settings_schema' => $manifest?->settings ?? Arr::get($result->manifestData, 'settings', []),
                'data_ownership' => $manifest?->dataOwnership ?? Arr::get($result->manifestData, 'data_ownership', []),
                'path' => $pluginPath,
                'source_type' => 'local',
                'available' => true,
                'validation_status' => $result->valid ? 'valid' : 'invalid',
                'validation_errors' => $result->errors,
                'last_discovered_at' => now(),
                'last_validated_at' => now(),
            ]);
            $record->save();

            $seenPaths[] = $pluginPath;
            $discovered[] = $record->fresh();
        }

        if ($seenPaths !== []) {
            ExtensionPlugin::query()
                ->whereNotIn('path', $seenPaths)
                ->update(['available' => false]);
        }

        return $discovered;
    }

    public function validate(ExtensionPlugin $plugin): ExtensionPlugin
    {
        $result = $this->validator->validatePath((string) $plugin->path);

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
            'settings_schema' => $result->manifest?->settings ?? $plugin->settings_schema,
            'data_ownership' => $result->manifest?->dataOwnership ?? $plugin->data_ownership,
            'validation_status' => $result->valid ? 'valid' : 'invalid',
            'validation_errors' => $result->errors,
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

            $instance = $this->instantiate($plugin);
            $context = new PluginExecutionContext(
                plugin: $plugin,
                run: $run,
                trigger: (string) ($options['trigger'] ?? 'manual'),
                dryRun: (bool) ($options['dry_run'] ?? false),
                hook: null,
                user: isset($options['user_id']) ? User::find($options['user_id']) : null,
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
            ->get()
            ->filter(fn (ExtensionPlugin $plugin) => in_array($hook, $plugin->hooks ?? [], true))
            ->values();
    }

    public function instantiate(ExtensionPlugin $plugin): PluginInterface
    {
        $plugin = $this->validate($plugin);

        if (! $plugin->isInstalled()) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] has been uninstalled and must be reinstalled before it can run.");
        }

        if ($plugin->validation_status !== 'valid') {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] is not valid.");
        }

        $entrypoint = rtrim((string) $plugin->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$plugin->entrypoint;
        require_once $entrypoint;

        $instance = app($plugin->class_name);
        if (! $instance instanceof PluginInterface) {
            throw new RuntimeException("Plugin [{$plugin->plugin_id}] did not resolve to a valid plugin instance.");
        }

        return $instance;
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

        return $this->validate($plugin->fresh());
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
        foreach ($ownership['files'] ?? [] as $file) {
            Storage::disk('local')->delete($file);
        }

        foreach ($ownership['directories'] ?? [] as $directory) {
            Storage::disk('local')->deleteDirectory($directory);
        }

        foreach ($ownership['tables'] ?? [] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
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
}
