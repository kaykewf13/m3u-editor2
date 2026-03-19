<?php

namespace App\Filament\Resources\ExtensionPlugins\Pages;

use App\Filament\Resources\ExtensionPlugins\ExtensionPluginResource;
use App\Jobs\ExecutePluginInvocation;
use App\Models\ExtensionPlugin;
use App\Models\ExtensionPluginRun;
use App\Models\PluginEpgRepairScanCandidate;
use App\Plugins\PluginManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

class ViewPluginRun extends Page
{
    use InteractsWithRecord;
    use WithPagination;

    protected static string $resource = ExtensionPluginResource::class;

    protected string $view = 'filament.resources.extension-plugins.pages.view-plugin-run';

    public ExtensionPluginRun $runRecord;

    public Collection $logs;

    public function getCandidateRowsProperty(): LengthAwarePaginator
    {
        return $this->runRecord->epgRepairCandidates()
            ->orderByDesc('repairable')
            ->orderByRaw('CASE review_status WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END', [
                'approved',
                'pending',
                'rejected',
            ])
            ->orderByDesc('confidence')
            ->orderBy('id')
            ->paginate(25, pageName: 'candidatesPage');
    }

    public function mount(int|string $record, int|string $run): void
    {
        app(PluginManager::class)->recoverStaleRuns();

        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();

        /** @var ExtensionPlugin $plugin */
        $plugin = $this->getRecord();
        $runRecord = $plugin->runs()
            ->with(['plugin', 'user'])
            ->find($run);

        if (! $runRecord) {
            throw (new ModelNotFoundException)->setModel(ExtensionPluginRun::class, [$run]);
        }

        abort_unless($runRecord->canBeViewedBy(auth()->user()), 403);

        $this->runRecord = $runRecord;
        $this->logs = $runRecord->logs()->latest()->limit(150)->get()->reverse()->values();
    }

    public function getTitle(): string
    {
        $label = $this->runRecord->action ?: $this->runRecord->hook ?: 'Plugin Run';

        return Str::headline($label).' #'.$this->runRecord->id;
    }

    public function isReviewableScanRun(): bool
    {
        return $this->runRecord->action === 'scan' && $this->runRecord->status === 'completed';
    }

    public function reviewDecisions(): array
    {
        return $this->candidateRows
            ->getCollection()
            ->mapWithKeys(fn (PluginEpgRepairScanCandidate $candidate): array => [
                (string) $candidate->channel_id => [
                    'status' => $candidate->review_status ?? 'pending',
                    'updated_at' => optional($candidate->reviewed_at)->toIso8601String(),
                    'user_id' => $candidate->reviewed_by_user_id,
                    'user_name' => $candidate->reviewed_by_user_name,
                ],
            ])
            ->all();
    }

    public function approvedReviewCount(): int
    {
        return (int) $this->runRecord->epgRepairCandidates()
            ->where('repairable', true)
            ->whereNotNull('suggested_epg_channel_id')
            ->where('review_status', 'approved')
            ->count();
    }

    public function markReviewDecision(int $channelId, string $status): void
    {
        if (! $this->isReviewableScanRun()) {
            return;
        }

        if (! in_array($status, ['approved', 'rejected', 'pending'], true)) {
            return;
        }

        $candidate = $this->runRecord->epgRepairCandidates()
            ->where('channel_id', $channelId)
            ->first();

        if (! $candidate || ! $candidate->repairable || ! filled($candidate->suggested_epg_channel_id)) {
            Notification::make()
                ->danger()
                ->title('Candidate not reviewable')
                ->body('Only persisted, repairable candidates can be approved or rejected from this screen.')
                ->send();

            return;
        }

        if ($candidate->review_status === 'applied') {
            Notification::make()
                ->warning()
                ->title('Candidate already applied')
                ->body('Applied review decisions are locked on the source scan run.')
                ->send();

            return;
        }

        $candidate->forceFill([
            'review_status' => $status,
            'reviewed_by_user_id' => $status === 'pending' ? null : auth()->id(),
            'reviewed_by_user_name' => $status === 'pending' ? null : auth()->user()?->name,
            'reviewed_at' => $status === 'pending' ? null : now(),
        ])->save();

        $this->syncRunReviewSnapshotFromCandidates();
        $this->runRecord->logs()->create([
            'level' => 'info',
            'message' => 'Candidate review updated.',
            'context' => [
                'channel_id' => $channelId,
                'review_status' => $status,
                'user_id' => auth()->id(),
            ],
        ]);

        $this->refreshRunState();

        Notification::make()
            ->success()
            ->title('Review updated')
            ->body(match ($status) {
                'approved' => 'Candidate approved for Apply Reviewed.',
                'rejected' => 'Candidate rejected and will be skipped by Apply Reviewed.',
                default => 'Candidate returned to pending review.',
            })
            ->send();
    }

    public function approveAllVisibleCandidates(): void
    {
        if (! $this->isReviewableScanRun()) {
            return;
        }

        $candidateIds = collect($this->candidateRows->items())
            ->filter(fn (PluginEpgRepairScanCandidate $candidate): bool => $candidate->repairable && filled($candidate->suggested_epg_channel_id) && $candidate->review_status !== 'applied')
            ->pluck('id')
            ->all();

        if ($candidateIds === []) {
            return;
        }

        $this->runRecord->epgRepairCandidates()
            ->whereIn('id', $candidateIds)
            ->update([
                'review_status' => 'approved',
                'reviewed_by_user_id' => auth()->id(),
                'reviewed_by_user_name' => auth()->user()?->name,
                'reviewed_at' => now(),
            ]);

        $this->syncRunReviewSnapshotFromCandidates();
        $this->runRecord->logs()->create([
            'level' => 'info',
            'message' => 'All visible reviewable candidates were approved.',
            'context' => [
                'candidate_count' => count($candidateIds),
                'user_id' => auth()->id(),
            ],
        ]);

        $this->refreshRunState();

        Notification::make()
            ->success()
            ->title('Visible candidates approved')
            ->body('Apply Reviewed can now consume these approved candidates on the current page.')
            ->send();
    }

    public function clearReviewDecisions(): void
    {
        if (! $this->isReviewableScanRun()) {
            return;
        }

        $this->runRecord->epgRepairCandidates()
            ->where('repairable', true)
            ->whereNotNull('suggested_epg_channel_id')
            ->where('review_status', '!=', 'applied')
            ->update([
                'review_status' => 'pending',
                'reviewed_by_user_id' => null,
                'reviewed_by_user_name' => null,
                'reviewed_at' => null,
            ]);

        $this->syncRunReviewSnapshotFromCandidates();
        $this->runRecord->logs()->create([
            'level' => 'info',
            'message' => 'Review decisions cleared by operator.',
            'context' => [
                'user_id' => auth()->id(),
            ],
        ]);

        $this->refreshRunState();

        Notification::make()
            ->success()
            ->title('Review decisions cleared')
            ->body('All persisted candidates are back to pending review.')
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download_report')
                ->label('Download Report')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(function (): bool {
                    $reportPath = data_get($this->runRecord->result, 'data.report.path')
                        ?? data_get($this->runRecord->run_state, 'epg_repair.report_path');

                    return filled($reportPath) && Storage::disk('local')->exists($reportPath);
                })
                ->url(fn (): string => route('extension-plugins.runs.report', [
                    'plugin' => $this->getRecord(),
                    'run' => $this->runRecord,
                ])),
            Action::make('approve_visible')
                ->label('Approve Visible')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn (): bool => $this->isReviewableScanRun())
                ->action(fn () => $this->approveAllVisibleCandidates()),
            Action::make('clear_review')
                ->label('Clear Review')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (): bool => $this->isReviewableScanRun())
                ->requiresConfirmation()
                ->action(fn () => $this->clearReviewDecisions()),
            Action::make('apply_reviewed')
                ->label('Apply Reviewed')
                ->icon('heroicon-o-check-badge')
                ->color('primary')
                ->visible(fn (): bool => $this->isReviewableScanRun() && $this->approvedReviewCount() > 0)
                ->requiresConfirmation()
                ->modalDescription('Only candidates you approved on this run will be applied. No fresh scan is performed.')
                ->action(function (): void {
                    dispatch(new ExecutePluginInvocation(
                        pluginId: $this->getRecord()->id,
                        invocationType: 'action',
                        name: 'apply_reviewed',
                        payload: [
                            'source_run_id' => $this->runRecord->id,
                        ],
                        options: [
                            'trigger' => 'manual',
                            'dry_run' => false,
                            'user_id' => auth()->id(),
                        ],
                    ));

                    Notification::make()
                        ->success()
                        ->title('Apply Reviewed queued')
                        ->body('A background run will apply only the candidates you approved on this scan.')
                        ->send();
                }),
            Action::make('stop_run')
                ->label('Stop Run')
                ->icon('heroicon-o-stop-circle')
                ->color('warning')
                ->visible(fn (): bool => $this->runRecord->status === 'running')
                ->requiresConfirmation()
                ->action(function (): void {
                    app(PluginManager::class)->requestCancellation($this->runRecord, auth()->id());
                    $this->runRecord = $this->runRecord->fresh();
                    $this->logs = $this->runRecord->logs()->latest()->limit(150)->get()->reverse()->values();

                    Notification::make()
                        ->success()
                        ->title('Cancellation requested')
                        ->body('The worker will stop the run at the next safe checkpoint.')
                        ->send();
                }),
            Action::make('resume_run')
                ->label('Resume Run')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->visible(fn (): bool => in_array($this->runRecord->status, ['cancelled', 'stale', 'failed'], true))
                ->action(function (): void {
                    app(PluginManager::class)->resumeRun($this->runRecord, auth()->id());
                    $this->runRecord = $this->runRecord->fresh();
                    $this->logs = $this->runRecord->logs()->latest()->limit(150)->get()->reverse()->values();

                    Notification::make()
                        ->success()
                        ->title('Run resumed')
                        ->body('The run was queued again and will continue from the last saved checkpoint when possible.')
                        ->send();
                }),
            Action::make('back_to_plugin')
                ->label('Back to Plugin')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => ExtensionPluginResource::getUrl('edit', [
                    'record' => $this->getRecord(),
                ])),
        ];
    }

    private function refreshRunState(): void
    {
        $this->runRecord = $this->runRecord->fresh(['plugin', 'user']);
        $this->logs = $this->runRecord->logs()->latest()->limit(150)->get()->reverse()->values();
    }

    private function syncRunReviewSnapshotFromCandidates(): void
    {
        $result = $this->runRecord->result ?? [];
        $data = $result['data'] ?? [];
        $previewIds = collect($data['channels_preview'] ?? [])
            ->pluck('channel_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $reviewableQuery = $this->runRecord->epgRepairCandidates()
            ->where('repairable', true)
            ->whereNotNull('suggested_epg_channel_id');

        $data['review'] = [
            'decisions' => $previewIds === []
                ? []
                : $this->runRecord->epgRepairCandidates()
                    ->whereIn('channel_id', $previewIds)
                    ->get()
                    ->mapWithKeys(fn (PluginEpgRepairScanCandidate $candidate): array => [
                        (string) $candidate->channel_id => [
                            'status' => $candidate->review_status ?? 'pending',
                            'updated_at' => optional($candidate->reviewed_at)->toIso8601String(),
                            'user_id' => $candidate->reviewed_by_user_id,
                            'user_name' => $candidate->reviewed_by_user_name,
                        ],
                    ])
                    ->all(),
            'counts' => [
                'approved' => (clone $reviewableQuery)->where('review_status', 'approved')->count(),
                'rejected' => (clone $reviewableQuery)->where('review_status', 'rejected')->count(),
                'applied' => (clone $reviewableQuery)->where('review_status', 'applied')->count(),
                'pending' => (clone $reviewableQuery)->where('review_status', 'pending')->count(),
            ],
            'updated_at' => now()->toIso8601String(),
        ];

        $result['data'] = $data;
        $this->runRecord->forceFill(['result' => $result])->save();
    }

}
