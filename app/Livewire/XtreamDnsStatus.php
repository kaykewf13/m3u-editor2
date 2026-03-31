<?php

namespace App\Livewire;

use App\Jobs\CheckXtreamDnsHealth;
use App\Models\Playlist;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class XtreamDnsStatus extends Component
{
    public Model $record;

    public bool $checking = false;

    public function mount(): void
    {
        // Dispatch background health check on mount
        if ($this->record instanceof Playlist && $this->hasMultipleUrls()) {
            CheckXtreamDnsHealth::dispatch($this->record->id);
        }
    }

    public function checkAll(): void
    {
        $this->checking = true;

        // Clear cache to force fresh check
        Cache::forget("xtream_dns_health:{$this->record->id}");
        CheckXtreamDnsHealth::dispatch($this->record->id);

        // Poll will pick up the results
        $this->checking = false;
    }

    public function getResults(): array
    {
        return Cache::get("xtream_dns_health:{$this->record->id}", []);
    }

    public function hasMultipleUrls(): bool
    {
        if (! $this->record instanceof Playlist) {
            return false;
        }

        return count($this->record->getOrderedXtreamUrls()) > 1;
    }

    public function render()
    {
        return view('livewire.xtream-dns-status');
    }
}
