<?php

namespace App\Jobs;

use App\Models\Playlist;
use App\Services\XtreamHealthService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class CheckXtreamDnsHealth implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $playlistId
    ) {}

    public function uniqueId(): string
    {
        return "dns_health_{$this->playlistId}";
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(2);
    }

    public function handle(): void
    {
        $playlist = Playlist::find($this->playlistId);
        if (! $playlist || ! $playlist->xtream_config) {
            return;
        }

        $results = XtreamHealthService::checkAllUrls($playlist);

        Cache::put(
            "xtream_dns_health:{$this->playlistId}",
            $results,
            now()->addMinutes(5)
        );
    }
}
