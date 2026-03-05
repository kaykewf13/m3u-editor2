<?php

namespace App\Filament\Resources\Networks\Pages;

use App\Filament\Resources\Networks\NetworkResource;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Network;
use App\Models\NetworkContent;
use App\Models\NetworkProgramme;
use Carbon\Carbon;
use DateTimeZone;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

class ManualScheduleBuilder extends Page
{
    use InteractsWithRecord;

    protected static string $resource = NetworkResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Schedule Builder';

    protected static ?string $title = 'Schedule Builder';

    protected string $view = 'filament.resources.networks.pages.manual-schedule-builder';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();
    }

    /**
     * Only show the Schedule Builder tab when schedule_type is manual.
     */
    public static function canAccess(array $parameters = []): bool
    {
        if (! parent::canAccess($parameters)) {
            return false;
        }

        // If we have a record parameter, check if it's a manual schedule
        if (isset($parameters['record'])) {
            $record = $parameters['record'];
            if ($record instanceof Network) {
                return $record->schedule_type === 'manual';
            }
        }

        return true;
    }

    /**
     * Resolve a validated timezone from the browser, falling back to UTC.
     */
    protected function resolveTimezone(string $tz): DateTimeZone
    {
        try {
            return new DateTimeZone($tz);
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * Format a programme record for the frontend, with times in the user's timezone.
     *
     * @param  array<string, mixed>  $extra  Additional fields to merge
     */
    protected function formatProgrammeResponse(NetworkProgramme $programme, DateTimeZone $tz): array
    {
        $localStart = $programme->start_time->copy()->setTimezone($tz);
        $localEnd = $programme->end_time->copy()->setTimezone($tz);

        return [
            'id' => $programme->id,
            'title' => $programme->title,
            'description' => $programme->description,
            'image' => $programme->image,
            'start_time' => $programme->start_time->toIso8601String(),
            'end_time' => $programme->end_time->toIso8601String(),
            'duration_seconds' => $programme->duration_seconds,
            'contentable_type' => $programme->contentable_type,
            'contentable_id' => $programme->contentable_id,
            'start_hour' => $localStart->format('H'),
            'start_minute' => $localStart->format('i'),
        ];
    }

    /**
     * Get schedule data for a specific date (in the user's local timezone).
     */
    public function getScheduleForDate(string $date, string $timezone = 'UTC'): array
    {
        $network = $this->getRecord();
        $tz = $this->resolveTimezone($timezone);

        // Parse day boundaries in the user's timezone, then convert to UTC for query
        $dayStart = Carbon::parse($date, $tz)->startOfDay()->utc();
        $dayEnd = Carbon::parse($date, $tz)->endOfDay()->utc();

        $programmes = $network->programmes()
            ->where('start_time', '>=', $dayStart)
            ->where('start_time', '<', $dayEnd)
            ->orderBy('start_time')
            ->get();

        return $programmes->map(fn (NetworkProgramme $programme) => $this->formatProgrammeResponse($programme, $tz)
        )->values()->toArray();
    }

    /**
     * Get the media pool (network content + optionally all media).
     */
    public function getMediaPool(bool $showAll = false): array
    {
        $network = $this->getRecord();

        if (! $showAll) {
            // Show only network's existing content
            $content = $network->networkContent()
                ->with('contentable')
                ->orderBy('sort_order')
                ->get();

            return $content->map(function (NetworkContent $item) {
                return $this->formatMediaItem($item->contentable, $item->contentable_type);
            })->filter()->values()->toArray();
        }

        // Show all media from the linked media server
        $playlistId = $network->mediaServerIntegration?->playlist_id;
        if (! $playlistId) {
            return [];
        }

        $items = collect();

        // Get movies (channels that are VOD from this playlist)
        $movies = Channel::where('playlist_id', $playlistId)
            ->whereNotNull('movie_data')
            ->orderBy('title')
            ->limit(500)
            ->get();

        foreach ($movies as $movie) {
            $items->push($this->formatMediaItem($movie, Channel::class));
        }

        // Get episodes from this playlist's series
        $episodes = Episode::whereHas('series', function ($q) use ($playlistId) {
            $q->where('playlist_id', $playlistId);
        })
            ->with('series')
            ->orderBy('title')
            ->limit(500)
            ->get();

        foreach ($episodes as $episode) {
            $items->push($this->formatMediaItem($episode, Episode::class));
        }

        return $items->filter()->values()->toArray();
    }

    /**
     * Format a media item for the pool display.
     */
    protected function formatMediaItem(mixed $content, string $type): ?array
    {
        if (! $content) {
            return null;
        }

        $duration = 0;

        if ($content instanceof Episode) {
            $title = $content->title;
            if ($content->series) {
                $seasonNum = $content->season ?? 1;
                $episodeNum = $content->episode_num ?? 1;
                $title = "{$content->series->name} S{$seasonNum}E{$episodeNum}";
            }
            $duration = (int) ($content->info['duration_secs'] ?? 0);
            if ($duration <= 0) {
                $duration = $this->parseDuration($content->info['duration'] ?? null);
            }
            $image = $content->cover
                ?? $content->info['movie_image'] ?? null
                ?? $content->info['cover_big'] ?? null;
        } elseif ($content instanceof Channel) {
            $title = $content->name ?? $content->title ?? 'Unknown';
            $duration = (int) ($content->info['duration_secs'] ?? 0);
            if ($duration <= 0) {
                $duration = $this->parseDuration($content->info['duration'] ?? null);
            }
            if ($duration <= 0) {
                $duration = (int) ($content->movie_data['info']['duration_secs'] ?? 0);
            }
            if ($duration <= 0) {
                $duration = $this->parseDuration($content->movie_data['info']['duration'] ?? null);
            }
            $image = $content->logo
                ?? $content->logo_internal
                ?? $content->movie_data['info']['cover_big'] ?? null
                ?? $content->movie_data['info']['movie_image'] ?? null;
        } else {
            return null;
        }

        // Default duration of 30 minutes if unknown
        if ($duration <= 0) {
            $duration = 1800;
        }

        return [
            'id' => $content->id,
            'type' => $type === Episode::class ? 'episode' : 'movie',
            'contentable_type' => $type,
            'contentable_id' => $content->id,
            'title' => $title,
            'duration_seconds' => $duration,
            'duration_display' => $this->formatDuration($duration),
            'image' => $image,
        ];
    }

    /**
     * Add a programme to the schedule.
     *
     * The date and startTime are in the user's local timezone. We convert to UTC for storage.
     */
    public function addProgramme(string $date, string $startTime, string $timezone, string $contentableType, int $contentableId, ?int $durationOverride = null): array
    {
        $network = $this->getRecord();
        $tz = $this->resolveTimezone($timezone);

        // Resolve the content
        $content = $contentableType === Episode::class
            ? Episode::find($contentableId)
            : Channel::find($contentableId);

        if (! $content) {
            Notification::make()->danger()->title('Content not found')->send();

            return ['success' => false];
        }

        // Ensure content is in the network's content list (add if from "all media" pool)
        $existingContent = NetworkContent::where('network_id', $network->id)
            ->where('contentable_type', $contentableType)
            ->where('contentable_id', $contentableId)
            ->first();

        if (! $existingContent) {
            $maxSort = $network->networkContent()->max('sort_order') ?? 0;
            NetworkContent::create([
                'network_id' => $network->id,
                'contentable_type' => $contentableType,
                'contentable_id' => $contentableId,
                'sort_order' => $maxSort + 1,
                'weight' => 1,
            ]);
        }

        // Parse start time in user's timezone, then convert to UTC
        $startDateTime = Carbon::parse("{$date} {$startTime}", $tz)->utc();

        // Get duration
        $formatted = $this->formatMediaItem($content, $contentableType);
        $duration = $durationOverride ?? ($formatted['duration_seconds'] ?? 1800);

        // Auto-stack: if the requested slot overlaps, find the next available slot
        $startDateTime = $this->findNextAvailableSlot($network, $startDateTime, $duration);
        $endDateTime = $startDateTime->copy()->addSeconds($duration);

        // Bail if auto-stacking pushed us past the end of the day (in user's timezone)
        $localDate = Carbon::parse($date, $tz)->toDateString();
        $startInLocalTz = $startDateTime->copy()->setTimezone($tz)->toDateString();
        if ($startInLocalTz !== $localDate) {
            Notification::make()->warning()->title('No available time remaining on this day')->send();

            return ['success' => false];
        }

        // Get title
        $title = $formatted['title'] ?? 'Unknown';

        // Get description and image
        $description = null;
        $image = $formatted['image'] ?? null;
        if ($content instanceof Episode) {
            $description = $content->info['plot'] ?? $content->plot ?? null;
        } elseif ($content instanceof Channel) {
            $description = $content->movie_data['info']['plot'] ?? null;
        }

        $programme = NetworkProgramme::create([
            'network_id' => $network->id,
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'start_time' => $startDateTime,
            'end_time' => $endDateTime,
            'duration_seconds' => $duration,
            'contentable_type' => $contentableType,
            'contentable_id' => $contentableId,
        ]);

        // Update schedule timestamp
        $network->update(['schedule_generated_at' => Carbon::now()]);

        return [
            'success' => true,
            'programme' => $this->formatProgrammeResponse($programme, $tz),
        ];
    }

    /**
     * Update a programme's time slot.
     *
     * The date and startTime are in the user's local timezone.
     */
    public function updateProgramme(int $programmeId, string $date, string $startTime, string $timezone = 'UTC', ?int $durationOverride = null): array
    {
        $network = $this->getRecord();
        $tz = $this->resolveTimezone($timezone);
        $programme = $network->programmes()->find($programmeId);

        if (! $programme) {
            return ['success' => false];
        }

        // Parse in user's timezone, convert to UTC
        $newStart = Carbon::parse("{$date} {$startTime}", $tz)->utc();
        $duration = $durationOverride ?? $programme->duration_seconds;

        // Auto-stack: if the requested slot overlaps, find the next available slot (excluding self)
        $newStart = $this->findNextAvailableSlot($network, $newStart, $duration, $programmeId);
        $newEnd = $newStart->copy()->addSeconds($duration);

        // Bail if auto-stacking pushed us past the end of the day (in user's timezone)
        $localDate = Carbon::parse($date, $tz)->toDateString();
        $startInLocalTz = $newStart->copy()->setTimezone($tz)->toDateString();
        if ($startInLocalTz !== $localDate) {
            Notification::make()->warning()->title('No available time remaining on this day')->send();

            return ['success' => false];
        }

        $programme->update([
            'start_time' => $newStart,
            'end_time' => $newEnd,
            'duration_seconds' => $duration,
        ]);

        return [
            'success' => true,
            'programme' => $this->formatProgrammeResponse($programme, $tz),
        ];
    }

    /**
     * Remove a programme from the schedule.
     */
    public function removeProgramme(int $programmeId): array
    {
        $network = $this->getRecord();
        $programme = $network->programmes()->find($programmeId);

        if (! $programme) {
            return ['success' => false];
        }

        $programme->delete();

        Notification::make()->success()->title('Programme removed')->send();

        return ['success' => true];
    }

    /**
     * Clear all programmes for a specific date (in user's local timezone).
     */
    public function clearDay(string $date, string $timezone = 'UTC'): array
    {
        $network = $this->getRecord();
        $tz = $this->resolveTimezone($timezone);

        $dayStart = Carbon::parse($date, $tz)->startOfDay()->utc();
        $dayEnd = Carbon::parse($date, $tz)->endOfDay()->utc();

        $count = $network->programmes()
            ->where('start_time', '>=', $dayStart)
            ->where('start_time', '<', $dayEnd)
            ->delete();

        Notification::make()
            ->success()
            ->title("Cleared {$count} programme(s)")
            ->send();

        return ['success' => true, 'removed' => $count];
    }

    /**
     * Copy a day's schedule to another date.
     *
     * Source and target dates are in the user's local timezone.
     */
    public function copyDaySchedule(string $sourceDate, string $targetDate, string $timezone = 'UTC'): array
    {
        $network = $this->getRecord();
        $tz = $this->resolveTimezone($timezone);

        $sourceDayStart = Carbon::parse($sourceDate, $tz)->startOfDay()->utc();
        $sourceDayEnd = Carbon::parse($sourceDate, $tz)->endOfDay()->utc();
        $targetDayStart = Carbon::parse($targetDate, $tz)->startOfDay()->utc();

        $dayDiff = $sourceDayStart->diffInDays($targetDayStart, false);

        $sourceProgrammes = $network->programmes()
            ->where('start_time', '>=', $sourceDayStart)
            ->where('start_time', '<', $sourceDayEnd)
            ->get();

        if ($sourceProgrammes->isEmpty()) {
            Notification::make()->warning()->title('No programmes to copy from this day')->send();

            return ['success' => false];
        }

        // Clear target day first
        $targetDayEnd = Carbon::parse($targetDate, $tz)->endOfDay()->utc();
        $network->programmes()
            ->where('start_time', '>=', $targetDayStart)
            ->where('start_time', '<', $targetDayEnd)
            ->delete();

        foreach ($sourceProgrammes as $programme) {
            NetworkProgramme::create([
                'network_id' => $network->id,
                'title' => $programme->title,
                'description' => $programme->description,
                'image' => $programme->image,
                'start_time' => $programme->start_time->copy()->addDays($dayDiff),
                'end_time' => $programme->end_time->copy()->addDays($dayDiff),
                'duration_seconds' => $programme->duration_seconds,
                'contentable_type' => $programme->contentable_type,
                'contentable_id' => $programme->contentable_id,
            ]);
        }

        $network->update(['schedule_generated_at' => Carbon::now()]);

        Notification::make()
            ->success()
            ->title("Copied {$sourceProgrammes->count()} programme(s) to {$targetDate}")
            ->send();

        return ['success' => true, 'copied' => $sourceProgrammes->count()];
    }

    /**
     * Apply the weekly template — replicate the current week's schedule across the schedule window.
     */
    public function applyWeeklyTemplate(): array
    {
        $network = $this->getRecord();
        $scheduleWindowDays = $network->schedule_window_days ?? 7;

        if ($scheduleWindowDays <= 7) {
            Notification::make()->info()->title('Schedule window is already one week')->send();

            return ['success' => true];
        }

        // Use the first 7 days as the template
        $templateStart = Carbon::now()->startOfDay();
        $templateEnd = $templateStart->copy()->addDays(7);

        $templateProgrammes = $network->programmes()
            ->where('start_time', '>=', $templateStart)
            ->where('start_time', '<', $templateEnd)
            ->get();

        if ($templateProgrammes->isEmpty()) {
            Notification::make()->warning()->title('No template programmes found in the first week')->send();

            return ['success' => false];
        }

        // Clear everything beyond the first week
        $network->programmes()
            ->where('start_time', '>=', $templateEnd)
            ->delete();

        // Replicate for each additional week
        $weeksToFill = (int) ceil(($scheduleWindowDays - 7) / 7);
        $created = 0;

        for ($week = 1; $week <= $weeksToFill; $week++) {
            foreach ($templateProgrammes as $programme) {
                $newStart = $programme->start_time->copy()->addWeeks($week);
                $newEnd = $programme->end_time->copy()->addWeeks($week);

                // Don't create beyond schedule window
                if ($newStart->gt($templateStart->copy()->addDays($scheduleWindowDays))) {
                    break;
                }

                NetworkProgramme::create([
                    'network_id' => $network->id,
                    'title' => $programme->title,
                    'description' => $programme->description,
                    'image' => $programme->image,
                    'start_time' => $newStart,
                    'end_time' => $newEnd,
                    'duration_seconds' => $programme->duration_seconds,
                    'contentable_type' => $programme->contentable_type,
                    'contentable_id' => $programme->contentable_id,
                ]);
                $created++;
            }
        }

        $network->update(['schedule_generated_at' => Carbon::now()]);

        Notification::make()
            ->success()
            ->title("Weekly template applied — {$created} programmes created")
            ->send();

        return ['success' => true, 'created' => $created];
    }

    /**
     * Find the next available slot starting from the given time.
     *
     * If the requested time overlaps with existing programmes, walk forward
     * past them until a gap large enough for the new duration is found.
     */
    protected function findNextAvailableSlot(Network $network, Carbon $desiredStart, int $durationSeconds, ?int $excludeProgrammeId = null): Carbon
    {
        $candidate = $desiredStart->copy();
        $dayEnd = $desiredStart->copy()->endOfDay();

        // Safety: limit iterations to prevent infinite loops
        $maxIterations = 100;
        $iteration = 0;

        while ($iteration < $maxIterations && $candidate->lt($dayEnd)) {
            $candidateEnd = $candidate->copy()->addSeconds($durationSeconds);

            $query = $network->programmes()
                ->where('start_time', '<', $candidateEnd)
                ->where('end_time', '>', $candidate);

            if ($excludeProgrammeId !== null) {
                $query->where('id', '!=', $excludeProgrammeId);
            }

            $blocker = $query->orderBy('end_time', 'desc')->first();

            if (! $blocker) {
                // No overlap — this slot is available
                return $candidate;
            }

            // Move candidate to right after the blocking programme ends
            $candidate = $blocker->end_time->copy();
            $iteration++;
        }

        // Fell through — return a time past end-of-day so caller can detect failure
        return $dayEnd->copy()->addSecond();
    }

    /**
     * Parse duration from various formats to seconds.
     */
    protected function parseDuration(mixed $duration): int
    {
        if ($duration === null) {
            return 0;
        }

        if (is_numeric($duration)) {
            return (int) $duration;
        }

        if (is_string($duration)) {
            if (preg_match('/^(\d+):(\d+):(\d+)$/', $duration, $matches)) {
                return ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) $matches[3];
            }
            if (preg_match('/^(\d+):(\d+)$/', $duration, $matches)) {
                return ((int) $matches[1] * 60) + (int) $matches[2];
            }
        }

        return 0;
    }

    /**
     * Format seconds into human-readable duration.
     */
    protected function formatDuration(int $seconds): string
    {
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm', $minutes);
    }

    /**
     * Get view data for the blade template.
     */
    public function getViewData(): array
    {
        $network = $this->getRecord();

        return [
            'network' => $network,
            'scheduleWindowDays' => $network->schedule_window_days ?? 7,
            'recurrenceMode' => $network->manual_schedule_recurrence ?? 'per_day',
        ];
    }
}
