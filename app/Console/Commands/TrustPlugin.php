<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;
use RuntimeException;

class TrustPlugin extends Command
{
    protected $signature = 'plugins:trust {pluginId} {--reason= : Optional review note to store with the trust decision}';

    protected $description = 'Mark a validated local plugin as trusted and pin its current integrity hashes.';

    public function handle(PluginManager $pluginManager): int
    {
        $pluginManager->discover();
        $plugin = $pluginManager->findPluginById((string) $this->argument('pluginId'));

        if (! $plugin) {
            $this->error('No matching plugin found.');

            return self::FAILURE;
        }

        try {
            $plugin = $pluginManager->trust($plugin, reason: $this->option('reason') ?: null);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Plugin [{$plugin->plugin_id}] is now trusted.");
        $this->line("Integrity status: {$plugin->integrity_status}");

        return self::SUCCESS;
    }
}
