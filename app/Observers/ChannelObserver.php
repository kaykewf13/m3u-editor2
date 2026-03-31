<?php

namespace App\Observers;

use App\Jobs\SyncPlexDvrJob;
use App\Models\Channel;

class ChannelObserver
{
    /**
     * Handle the Channel "updated" event.
     *
     * Dispatches a Plex DVR sync when the enabled status changes.
     * SyncPlexDvrJob is ShouldBeUnique (60s window), so rapid
     * individual toggles are automatically debounced.
     */
    public function updated(Channel $channel): void
    {
        if ($channel->wasChanged('enabled')) {
            dispatch(new SyncPlexDvrJob(trigger: 'channel_observer'));
        }
    }
}
