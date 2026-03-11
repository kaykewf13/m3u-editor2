<?php

namespace App\Filament\Pages;

use App\Facades\GitInfo;
use App\Providers\VersionServiceProvider;
use Filament\Pages\Page;
use Illuminate\Support\Str;

class ReleaseLogs extends Page
{
    protected static ?string $navigationLabel = 'Release Logs';

    protected static ?string $title = 'Release Logs';

    protected static string|\UnitEnum|null $navigationGroup = 'Tools';

    protected static ?int $navigationSort = 3;

    public function getView(): string
    {
        return 'filament.pages.release-logs';
    }

    public string $filter = 'all';

    public array $allReleases = [];

    public string $currentVersion = '';

    public string $currentBranch = '';

    public function mount(): void
    {
        $this->currentVersion = VersionServiceProvider::getVersion();
        $this->currentBranch = GitInfo::getBranch() ?? 'master';
        $this->loadReleases();
    }

    protected function loadReleases(): void
    {
        $stored = VersionServiceProvider::getStoredReleases();
        $releases = ! empty($stored) ? $stored : VersionServiceProvider::fetchReleases(50);

        $normalizedCurrent = ltrim((string) $this->currentVersion, 'v');

        $this->allReleases = array_map(function ($r) use ($normalizedCurrent) {
            $tag = $r['tag_name'] ?? ($r['name'] ?? null);
            $normalizedTag = ltrim((string) $tag, 'v');

            if (str_ends_with($normalizedTag, '-dev')) {
                $type = 'dev';
            } elseif (str_ends_with($normalizedTag, '-exp')) {
                $type = 'experimental';
            } else {
                $type = 'latest';
            }

            return [
                'tag' => $tag,
                'name' => $r['name'] ?? $r['tag_name'] ?? '',
                'url' => $r['html_url'] ?? null,
                'body' => $r['body'] ?? '',
                'published_at' => $r['published_at'] ?? null,
                'prerelease' => $r['prerelease'] ?? false,
                'type' => $type,
                'is_current' => $tag !== null && $normalizedTag === $normalizedCurrent,
            ];
        }, $releases ?: []);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    public function getFilteredReleases(): array
    {
        if ($this->filter === 'all') {
            return $this->allReleases;
        }

        return array_values(array_filter($this->allReleases, fn ($r) => $r['type'] === $this->filter));
    }

    public function formatMarkdown(string $text): string
    {
        return Str::markdown($text);
    }

    public function getCounts(): array
    {
        $counts = ['all' => \count($this->allReleases), 'latest' => 0, 'dev' => 0, 'experimental' => 0];
        foreach ($this->allReleases as $r) {
            $counts[$r['type']] = ($counts[$r['type']] ?? 0) + 1;
        }

        return $counts;
    }

    public function getViewData(): array
    {
        return [
            'releases' => $this->getFilteredReleases(),
            'filter' => $this->filter,
            'counts' => $this->getCounts(),
            'currentVersion' => $this->currentVersion,
            'currentBranch' => $this->currentBranch,
        ];
    }
}
