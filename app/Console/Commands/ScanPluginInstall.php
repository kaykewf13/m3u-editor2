<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;
use Throwable;

class ScanPluginInstall extends Command
{
    protected $signature = 'plugins:scan-install {reviewId : Plugin install review id}';

    protected $description = 'Run the malware scan for a staged plugin install review.';

    public function handle(PluginManager $pluginManager): int
    {
        $review = $pluginManager->findInstallReviewById((int) $this->argument('reviewId'));
        if (! $review) {
            $this->error('No matching install review found.');

            return self::FAILURE;
        }

        try {
            $review = $pluginManager->scanInstallReview($review);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Review #{$review->id} scan status: {$review->scan_status}");
        $this->line($review->scan_summary ?: 'No scan summary recorded.');

        return $review->scan_status === 'clean' ? self::SUCCESS : self::FAILURE;
    }
}
