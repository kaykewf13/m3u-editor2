<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;
use Throwable;

class StagePluginDirectory extends Command
{
    protected $signature = 'plugins:stage-directory {path : Absolute or relative path to a plugin directory} {--dev : Mark this review as a local dev-source plugin}';

    protected $description = 'Stage a local plugin directory for reviewed install.';

    public function handle(PluginManager $pluginManager): int
    {
        try {
            $review = $pluginManager->stageDirectoryReview(
                (string) $this->argument('path'),
                auth()->id(),
                (bool) $this->option('dev'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Created install review #{$review->id} for plugin [{$review->plugin_id}]");
        $this->line("Status: {$review->status}");
        $this->line("Scan status: {$review->scan_status}");

        return self::SUCCESS;
    }
}
