<?php

namespace App\Plugins;

use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Support\PluginManifest;
use App\Plugins\Support\PluginValidationResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class PluginValidator
{
    public function __construct(
        private readonly PluginManifestLoader $loader,
        private readonly PluginIntegrityService $integrityService,
    ) {}

    public function validatePath(string $pluginPath): PluginValidationResult
    {
        $errors = [];
        $manifest = null;
        $manifestData = [];
        $pluginId = basename($pluginPath);

        try {
            $manifest = $this->loader->load($pluginPath);
            $manifestData = $manifest->raw;
            $pluginId = $manifest->id;
        } catch (Throwable $exception) {
            return new PluginValidationResult(false, [$exception->getMessage()], null, $manifestData, $pluginId, []);
        }

        $hashes = $this->integrityService->hashesForPlugin($pluginPath, $manifest->entrypoint);

        foreach (['id', 'name', 'entrypoint', 'class'] as $key) {
            if (blank($manifestData[$key] ?? null)) {
                $errors[] = "Missing required manifest field [{$key}]";
            }
        }

        if (($manifestData['api_version'] ?? null) !== config('plugins.api_version')) {
            $errors[] = 'Plugin api_version does not match host plugin API version.';
        }

        $knownCapabilities = array_keys(config('plugins.capabilities', []));
        foreach ($manifest->capabilities as $capability) {
            if (! in_array($capability, $knownCapabilities, true)) {
                $errors[] = "Unknown capability [{$capability}]";
            }
        }

        $knownHooks = config('plugins.hooks', []);
        foreach ($manifest->hooks as $hook) {
            if (! is_string($hook) || ! in_array($hook, $knownHooks, true)) {
                $errors[] = "Unknown hook [{$hook}]";
            }
        }

        $knownPermissions = array_keys(config('plugins.permissions', []));
        foreach ($manifest->permissions as $permission) {
            if (! in_array($permission, $knownPermissions, true)) {
                $errors[] = "Unknown permission [{$permission}]";
            }
        }

        if (($manifest->actions !== [] || $manifest->hooks !== [] || in_array('scheduled', $manifest->capabilities, true))
            && ! in_array('queue_jobs', $manifest->permissions, true)) {
            $errors[] = 'Plugins that queue actions, hooks, or schedules must declare [queue_jobs].';
        }

        if ($manifest->hooks !== [] && ! in_array('hook_subscriptions', $manifest->permissions, true)) {
            $errors[] = 'Plugins declaring hooks must declare [hook_subscriptions].';
        }

        if (in_array('scheduled', $manifest->capabilities, true) && ! in_array('scheduled_runs', $manifest->permissions, true)) {
            $errors[] = 'Plugins using the [scheduled] capability must declare [scheduled_runs].';
        }

        if (($manifest->schema['tables'] ?? []) !== [] && ! in_array('schema_manage', $manifest->permissions, true)) {
            $errors[] = 'Plugins declaring [schema.tables] must declare [schema_manage].';
        }

        if ((($manifest->dataOwnership['directories'] ?? []) !== [] || ($manifest->dataOwnership['files'] ?? []) !== [])
            && ! in_array('filesystem_write', $manifest->permissions, true)) {
            $errors[] = 'Plugins declaring owned files or directories must declare [filesystem_write].';
        }

        $fieldTypes = config('plugins.field_types', []);
        foreach ($manifest->settings as $field) {
            $errors = [...$errors, ...$this->validateFieldDefinition($field, $fieldTypes, 'settings')];
        }

        $actionIds = [];
        foreach ($manifest->actions as $action) {
            $actionId = $action['id'] ?? null;
            if (blank($actionId)) {
                $errors[] = 'Action missing required field [id]';
                continue;
            }

            if (in_array($actionId, $actionIds, true)) {
                $errors[] = "Duplicate action id [{$actionId}]";
            }

            $actionIds[] = $actionId;

            foreach ($action['fields'] ?? [] as $field) {
                $errors = [...$errors, ...$this->validateFieldDefinition($field, $fieldTypes, "actions.{$actionId}")];
            }
        }

        $errors = [...$errors, ...$this->validateDataOwnership($manifest)];
        $errors = [...$errors, ...$this->validateSchema($manifest)];

        if (! file_exists($manifest->entrypointPath())) {
            $errors[] = "Missing entrypoint file [{$manifest->entrypoint}]";
        } else {
            try {
                require_once $manifest->entrypointPath();
            } catch (Throwable $exception) {
                $errors[] = "Entrypoint failed to load: {$exception->getMessage()}";
            }
        }

        if (! class_exists($manifest->className)) {
            $errors[] = "Plugin class [{$manifest->className}] was not found.";
        } else {
            if (! is_subclass_of($manifest->className, PluginInterface::class)) {
                $errors[] = "Plugin class [{$manifest->className}] must implement ".PluginInterface::class;
            }

            foreach ($manifest->capabilities as $capability) {
                $requiredInterface = config("plugins.capabilities.{$capability}");
                if ($requiredInterface && ! is_subclass_of($manifest->className, $requiredInterface)) {
                    $errors[] = "Plugin class [{$manifest->className}] must implement [{$requiredInterface}] for capability [{$capability}]";
                }
            }

            if ($manifest->hooks !== [] && ! is_subclass_of($manifest->className, HookablePluginInterface::class)) {
                $errors[] = "Plugin class [{$manifest->className}] must implement ".HookablePluginInterface::class.' when hooks are declared.';
            }
        }

        return new PluginValidationResult($errors === [], $errors, $manifest, $manifestData, $pluginId, $hashes);
    }

    private function validateFieldDefinition(array $field, array $fieldTypes, string $group): array
    {
        $errors = [];
        $fieldId = $field['id'] ?? null;

        if (blank($fieldId)) {
            return ["{$group} field is missing [id]"];
        }

        $type = $field['type'] ?? 'text';
        if (! in_array($type, $fieldTypes, true)) {
            $errors[] = "{$group}.{$fieldId} uses unsupported type [{$type}]";
        }

        if (in_array($type, ['select', 'model_select'], true) && blank($field['label'] ?? null)) {
            $errors[] = "{$group}.{$fieldId} should define a human-friendly [label]";
        }

        if ($type === 'select' && empty($field['options'])) {
            $errors[] = "{$group}.{$fieldId} select fields require [options]";
        }

        if ($type === 'model_select' && blank($field['model'] ?? null)) {
            $errors[] = "{$group}.{$fieldId} model_select fields require [model]";
        }

        return $errors;
    }

    private function validateDataOwnership(PluginManifest $manifest): array
    {
        $errors = [];
        $ownership = $manifest->dataOwnership;

        if (! in_array($ownership['default_cleanup_policy'] ?? null, config('plugins.cleanup_modes', []), true)) {
            $errors[] = 'data_ownership.default_cleanup_policy must be one of the supported cleanup modes.';
        }

        $tablePrefix = (string) ($ownership['table_prefix'] ?? '');
        foreach ($ownership['tables'] ?? [] as $table) {
            if (! Str::startsWith($table, $tablePrefix)) {
                $errors[] = "Declared table [{$table}] must start with [{$tablePrefix}] so uninstall can safely purge plugin-owned data.";
            }
        }

        $allowedRoots = collect(config('plugins.owned_storage_roots', []))
            ->map(fn (string $root) => trim($root, '/'))
            ->filter()
            ->all();

        foreach (['directories', 'files'] as $group) {
            foreach ($ownership[$group] ?? [] as $path) {
                if (Str::startsWith($path, '/') || Str::contains($path, ['..', '\\'])) {
                    $errors[] = "Declared {$group} path [{$path}] must stay inside approved storage roots.";
                    continue;
                }

                if (! collect($allowedRoots)->contains(fn (string $root) => Str::startsWith($path, $root.'/') || $path === $root)) {
                    $errors[] = "Declared {$group} path [{$path}] must start with one of: ".implode(', ', $allowedRoots);
                    continue;
                }

                if (! Str::contains($path, '/'.$manifest->id) && ! Str::contains($path, '/'.Str::of($manifest->id)->replace('-', '_')->value())) {
                    $errors[] = "Declared {$group} path [{$path}] must include the plugin id so cleanup stays namespaced.";
                }
            }
        }

        return $errors;
    }

    private function validateSchema(PluginManifest $manifest): array
    {
        $errors = [];
        $tablePrefix = (string) data_get($manifest->dataOwnership, 'table_prefix', '');
        $supportedColumnTypes = config('plugins.schema_column_types', []);
        $supportedIndexTypes = config('plugins.schema_index_types', []);

        foreach ($manifest->schema['tables'] ?? [] as $table) {
            $tableName = trim((string) ($table['name'] ?? ''));

            if ($tableName === '') {
                $errors[] = 'Schema tables require [name].';
                continue;
            }

            if (! Str::startsWith($tableName, $tablePrefix)) {
                $errors[] = "Declared schema table [{$tableName}] must start with [{$tablePrefix}].";
            }

            if (($table['columns'] ?? []) === []) {
                $errors[] = "Declared schema table [{$tableName}] must define at least one column.";
            }

            foreach ($table['columns'] ?? [] as $index => $column) {
                $columnPath = "schema.tables.{$tableName}.columns.{$index}";
                $type = $column['type'] ?? null;

                if (! is_string($type) || ! in_array($type, $supportedColumnTypes, true)) {
                    $errors[] = "{$columnPath} uses unsupported type [{$type}]";
                    continue;
                }

                if ($type !== 'timestamps' && blank($column['name'] ?? null)) {
                    $errors[] = "{$columnPath} requires [name]";
                }

                if ($type === 'foreignId' && blank($column['references'] ?? null)) {
                    $errors[] = "{$columnPath} foreignId columns require [references]";
                }
            }

            foreach ($table['indexes'] ?? [] as $index => $definition) {
                $indexPath = "schema.tables.{$tableName}.indexes.{$index}";
                $indexType = $definition['type'] ?? 'index';

                if (! in_array($indexType, $supportedIndexTypes, true)) {
                    $errors[] = "{$indexPath} uses unsupported type [{$indexType}]";
                }

                if (Arr::wrap($definition['columns'] ?? []) === []) {
                    $errors[] = "{$indexPath} requires [columns]";
                }
            }
        }

        return $errors;
    }
}
