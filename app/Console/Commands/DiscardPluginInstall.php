<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;
use Throwable;

class DiscardPluginInstall extends Command
{
    protected $signature = 'plugins:discard-install {reviewId : Plugin install review id}';

    protected $description = 'Discard a non-installed plugin install review and remove its staged files.';

    public function handle(PluginManager $pluginManager): int
    {
        $review = $pluginManager->findInstallReviewById((int) $this->argument('reviewId'));
        if (! $review) {
            $this->error('No matching install review found.');

            return self::FAILURE;
        }

        try {
            $pluginManager->discardInstallReview($review);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Review #{$review->id} discarded.");

        return self::SUCCESS;
    }
}
