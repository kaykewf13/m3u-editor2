<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;

class BlockPlugin extends Command
{
    protected $signature = 'plugins:block {pluginId} {--reason= : Optional reason shown in the plugin registry}';

    protected $description = 'Block a plugin from running until it is explicitly trusted again.';

    public function handle(PluginManager $pluginManager): int
    {
        $pluginManager->discover();
        $plugin = $pluginManager->findPluginById((string) $this->argument('pluginId'));

        if (! $plugin) {
            $this->error('No matching plugin found.');

            return self::FAILURE;
        }

        $plugin = $pluginManager->block($plugin, $this->option('reason') ?: null);

        $this->info("Plugin [{$plugin->plugin_id}] is now blocked.");

        return self::SUCCESS;
    }
}
