<?php

namespace App\Plugins;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PluginIntegrityService
{
    public function hashesForPlugin(string $pluginPath, ?string $entrypoint = null): array
    {
        if (! is_dir($pluginPath)) {
            return [
                'manifest_hash' => null,
                'entrypoint_hash' => null,
                'plugin_hash' => null,
                'files' => [],
            ];
        }

        $manifestPath = rtrim($pluginPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'plugin.json';
        $entrypointPath = $entrypoint
            ? rtrim($pluginPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$entrypoint
            : null;

        $files = $this->hashableFiles($pluginPath)
            ->mapWithKeys(function (string $path) use ($pluginPath): array {
                $relativePath = Str::after($path, rtrim($pluginPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);

                return [$relativePath => hash_file('sha256', $path)];
            })
            ->sortKeys()
            ->all();

        return [
            'manifest_hash' => is_file($manifestPath) ? hash_file('sha256', $manifestPath) : null,
            'entrypoint_hash' => ($entrypointPath && is_file($entrypointPath)) ? hash_file('sha256', $entrypointPath) : null,
            'plugin_hash' => $files === [] ? null : hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES)),
            'files' => $files,
        ];
    }

    private function hashableFiles(string $pluginPath): Collection
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pluginPath, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        return collect(iterator_to_array($iterator))
            ->filter(fn ($item): bool => $item->isFile())
            ->map(fn ($item): string => $item->getPathname())
            ->filter(fn (string $path): bool => in_array(pathinfo($path, PATHINFO_EXTENSION), ['php', 'json'], true))
            ->values();
    }
}
