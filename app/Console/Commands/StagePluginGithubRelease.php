<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;
use Throwable;

class StagePluginGithubRelease extends Command
{
    protected $signature = 'plugins:stage-github-release
        {url : GitHub release asset URL using the /owner/repo/releases/download/tag/asset pattern}
        {--sha256= : Expected SHA-256 checksum for the release asset}';

    protected $description = 'Download and stage a GitHub release plugin archive for reviewed install.';

    public function handle(PluginManager $pluginManager): int
    {
        $sha256 = trim((string) $this->option('sha256'));
        if ($sha256 === '') {
            $this->error('The --sha256 option is required for GitHub release installs.');

            return self::FAILURE;
        }

        try {
            $review = $pluginManager->stageGithubReleaseReview(
                (string) $this->argument('url'),
                $sha256,
                auth()->id(),
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
