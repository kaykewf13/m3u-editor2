<?php

namespace App\Plugins\Support;

class PluginValidationResult
{
    public function __construct(
        public readonly bool $valid,
        public readonly array $errors = [],
        public readonly ?PluginManifest $manifest = null,
        public readonly array $manifestData = [],
        public readonly ?string $pluginId = null,
        public readonly array $hashes = [],
    ) {}
}
