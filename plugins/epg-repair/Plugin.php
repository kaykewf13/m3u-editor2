<?php

namespace AppLocalPlugins\EpgRepair;

use App\Enums\Status;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\ExtensionPluginRun;
use App\Models\Playlist;
use App\Models\PluginEpgRepairScanCandidate;
use App\Plugins\Contracts\EpgRepairPluginInterface;
use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Contracts\ScheduledPluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use App\Services\ChannelTitleNormalizerService;
use App\Services\EpgCacheService;
use App\Services\SimilaritySearchService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Plugin implements EpgRepairPluginInterface, HookablePluginInterface, ScheduledPluginInterface
{
    private const SCAN_CHUNK_SIZE = 250;

    private const CHECKPOINT_EVERY_CHUNKS = 5;

    private const MAX_DETAILED_APPLY_LOGS = 25;

    private const MAX_RESULT_CHANNELS = 50;

    private const MAX_SOURCE_CANDIDATES = 3;

    public function __construct(
        private readonly SimilaritySearchService $similaritySearch,
        private readonly ChannelTitleNormalizerService $normalizer,
        private readonly EpgCacheService $cacheService,
    ) {}

    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return match ($action) {
            'scan' => $this->scan($payload, $context, false),
            'apply' => $this->apply($payload, $context),
            'apply_reviewed' => $this->applyReviewed($payload, $context),
            default => PluginActionResult::failure("Unsupported action [{$action}]"),
        };
    }

    public function runHook(string $hook, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        if ($hook !== 'epg.cache.generated') {
            return PluginActionResult::success("Hook [{$hook}] ignored by EPG Repair.");
        }

        if (! ($context->settings['auto_scan_on_epg_ready'] ?? false)) {
            return PluginActionResult::success('Auto scan is disabled.');
        }

        $playlistId = $context->settings['default_playlist_id'] ?? ($payload['playlist_ids'][0] ?? null);
        $epgId = $context->settings['default_epg_id'] ?? ($payload['epg_id'] ?? null);

        if (! $playlistId || ! $epgId) {
            return PluginActionResult::success('Auto scan skipped because no default playlist or EPG is configured.');
        }

        return $this->scan([
            'playlist_id' => $playlistId,
            'epg_id' => $epgId,
            'hours_ahead' => $context->settings['hours_ahead'] ?? 12,
            'confidence_threshold' => $context->settings['confidence_threshold'] ?? 0.65,
        ], $context, true);
    }

    public function scheduledActions(CarbonInterface $now, array $settings): array
    {
        if (! ($settings['schedule_enabled'] ?? false)) {
            return [];
        }

        $playlistId = $settings['default_playlist_id'] ?? null;
        $epgId = $settings['default_epg_id'] ?? null;
        $cron = $settings['schedule_cron'] ?? null;

        if (! $playlistId || ! $epgId || ! is_string($cron) || ! CronExpression::isValidExpression($cron)) {
            return [];
        }

        $expression = new CronExpression($cron);
        if (! $expression->isDue($now)) {
            return [];
        }

        return [[
            'type' => 'action',
            'name' => 'scan',
            'payload' => [
                'playlist_id' => $playlistId,
                'epg_id' => $epgId,
                'hours_ahead' => $settings['hours_ahead'] ?? 12,
                'confidence_threshold' => $settings['confidence_threshold'] ?? 0.65,
            ],
            'dry_run' => true,
        ]];
    }

    private function scan(array $payload, PluginExecutionContext $context, bool $implicitDryRun): PluginActionResult
    {
        [$playlist, $epg] = $this->resolveTargets($payload, $context->settings);
        if (! $playlist || ! $epg) {
            $context->error('EPG Repair scan failed because the selected playlist or EPG could not be resolved.', [
                'playlist_id' => $payload['playlist_id'] ?? $context->settings['default_playlist_id'] ?? null,
                'epg_id' => $payload['epg_id'] ?? $context->settings['default_epg_id'] ?? null,
            ]);
            return PluginActionResult::failure('Playlist or EPG could not be resolved.');
        }

        $hoursAhead = max(1, (int) ($payload['hours_ahead'] ?? $context->settings['hours_ahead'] ?? 12));
        $threshold = min(1, max(0.1, (float) ($payload['confidence_threshold'] ?? $context->settings['confidence_threshold'] ?? 0.65)));
        $sourceScope = $payload['source_scope'] ?? 'selected_only';

        $context->info('Starting EPG Repair scan.', [
            'playlist_id' => $playlist->id,
            'playlist_name' => $playlist->name,
            'epg_id' => $epg->id,
            'epg_name' => $epg->name,
            'hours_ahead' => $hoursAhead,
            'confidence_threshold' => $threshold,
            'source_scope' => $sourceScope,
            'dry_run' => $context->dryRun || $implicitDryRun,
        ]);

        [$issues, $cancelled] = $this->processRepairStream(
            playlist: $playlist,
            epg: $epg,
            hoursAhead: $hoursAhead,
            threshold: $threshold,
            context: $context,
            applyRepairs: false,
            sourceScope: $sourceScope,
            applyOptions: [],
        );

        if ($issues['totals']['channels_scanned'] === 0) {
            $context->warning('Scan found no enabled live channels in the selected playlist.', [
                'playlist_id' => $playlist->id,
                'playlist_name' => $playlist->name,
            ]);
        } else {
            $context->info('Scan completed.', [
                'channels_scanned' => $issues['totals']['channels_scanned'],
                'issues_found' => $issues['totals']['issues_found'],
                'repair_candidates' => $issues['totals']['repair_candidates'],
                'epg_channels_available' => $issues['totals']['epg_channels_available'],
                'channels_with_existing_programmes' => $issues['totals']['channels_with_existing_programmes'],
            ]);
        }

        if ($cancelled) {
            return PluginActionResult::cancelled(
                sprintf(
                    'Scan stopped after checking %d channels. Resume the run to continue from the last saved checkpoint.',
                    $issues['totals']['channels_scanned'],
                ),
                [
                    'dry_run' => $context->dryRun || $implicitDryRun,
                    ...$this->resultSnapshot($issues, $context->run),
                ],
            );
        }

        $summary = $issues['totals']['channels_scanned'] === 0
            ? 'Scanned 0 channels. The selected playlist currently has no enabled live channels to inspect.'
            : sprintf(
                'Scanned %d channels and found %d repair candidate(s).',
                $issues['totals']['channels_scanned'],
                $issues['totals']['repair_candidates']
            );

        return PluginActionResult::success(
            $summary,
            [
                'dry_run' => $context->dryRun || $implicitDryRun,
                ...$this->resultSnapshot($issues, $context->run),
            ],
        );
    }

    private function apply(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        [$playlist, $epg] = $this->resolveTargets($payload, $context->settings);
        if (! $playlist || ! $epg) {
            $context->error('EPG Repair apply failed because the selected playlist or EPG could not be resolved.', [
                'playlist_id' => $payload['playlist_id'] ?? $context->settings['default_playlist_id'] ?? null,
                'epg_id' => $payload['epg_id'] ?? $context->settings['default_epg_id'] ?? null,
            ]);
            return PluginActionResult::failure('Playlist or EPG could not be resolved.');
        }

        $hoursAhead = max(1, (int) ($payload['hours_ahead'] ?? $context->settings['hours_ahead'] ?? 12));
        $threshold = min(1, max(0.1, (float) ($payload['confidence_threshold'] ?? $context->settings['confidence_threshold'] ?? 0.65)));
        $applyOptions = [
            'apply_scope' => $payload['apply_scope'] ?? 'safe_only',
            'allow_source_switch' => (bool) ($payload['allow_source_switch'] ?? false),
            'max_repairs' => max(0, (int) ($payload['max_repairs'] ?? 250)),
        ];

        $context->info('Starting EPG Repair apply run.', [
            'playlist_id' => $playlist->id,
            'playlist_name' => $playlist->name,
            'epg_id' => $epg->id,
            'epg_name' => $epg->name,
            'hours_ahead' => $hoursAhead,
            'confidence_threshold' => $threshold,
            ...$applyOptions,
        ]);

        [$report, $cancelled] = $this->processRepairStream(
            playlist: $playlist,
            epg: $epg,
            hoursAhead: $hoursAhead,
            threshold: $threshold,
            context: $context,
            applyRepairs: true,
            sourceScope: 'selected_only',
            applyOptions: $applyOptions,
        );

        $applied = $report['totals']['repairs_applied'] ?? 0;

        if ($cancelled) {
            return PluginActionResult::cancelled(
                "Apply stopped after {$applied} EPG repair(s). Resume the run to continue from the last saved checkpoint.",
                [
                    'dry_run' => false,
                    ...$this->resultSnapshot($report, $context->run),
                ],
            );
        }

        if (($report['totals']['repairs_applied'] ?? 0) === 0) {
            $context->warning('Apply finished with no repairs applied.', [
                'issues_found' => $report['totals']['issues_found'],
                'repair_candidates' => $report['totals']['repair_candidates'],
            ]);
        }

        return PluginActionResult::success(
            "Applied {$applied} EPG repair(s).",
            [
                'dry_run' => false,
                ...$this->resultSnapshot($report, $context->run),
            ],
        );
    }

    private function applyReviewed(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $sourceRunId = (int) ($payload['source_run_id'] ?? 0);
        $sourceRun = $sourceRunId > 0
            ? ExtensionPluginRun::query()
                ->where('extension_plugin_id', $context->plugin->id)
                ->find($sourceRunId)
            : null;

        if (! $sourceRun || $sourceRun->action !== 'scan' || $sourceRun->status !== 'completed') {
            return PluginActionResult::failure('A completed scan run is required before reviewed repairs can be applied.');
        }

        $sourceData = data_get($sourceRun->result, 'data', []);
        $approved = $sourceRun->epgRepairCandidates()
            ->where('repairable', true)
            ->whereNotNull('suggested_epg_channel_id')
            ->where('review_status', 'approved')
            ->orderBy('id')
            ->get();

        if ($approved->isEmpty()) {
            $legacyApproved = collect(data_get($sourceData, 'review.decisions', []))
                ->filter(fn (array $decision): bool => ($decision['status'] ?? null) === 'approved')
                ->map(fn (array $decision) => $decision['item'] ?? null)
                ->filter(fn ($item): bool => is_array($item) && filled($item['suggested_epg_channel_id'] ?? null))
                ->values();

            if ($legacyApproved->isNotEmpty()) {
                foreach ($legacyApproved as $item) {
                    $item['review_status'] = 'approved';
                    $this->persistCandidate($sourceRun, $item);
                }

                $approved = $sourceRun->epgRepairCandidates()
                    ->where('repairable', true)
                    ->whereNotNull('suggested_epg_channel_id')
                    ->where('review_status', 'approved')
                    ->orderBy('id')
                    ->get();
            }
        }

        if ($approved->isEmpty()) {
            return PluginActionResult::failure('No approved repair candidates were found on the selected scan run.');
        }

        $playlist = data_get($sourceData, 'playlist');
        $epg = data_get($sourceData, 'epg');

        $context->info('Starting Apply Reviewed run.', [
            'source_run_id' => $sourceRun->id,
            'approved_candidates' => $approved->count(),
            'playlist_id' => data_get($playlist, 'id'),
            'epg_id' => data_get($epg, 'id'),
        ]);

        $checkpoint = $this->prepareReportState($context, [
            'mode' => 'apply_reviewed',
            'playlist_id' => data_get($playlist, 'id'),
            'epg_id' => data_get($epg, 'id'),
            'source_scope' => data_get($sourceData, 'source_scope', 'selected_only'),
            'total_channels' => $approved->count(),
            'epg_channels_available' => 0,
            'compared_epg_sources' => (int) data_get($sourceData, 'totals.compared_epg_sources', 1),
            'cross_source_candidates' => 0,
            'last_channel_id' => null,
            'channels_scanned' => 0,
            'issues_found' => $approved->count(),
            'repair_candidates' => $approved->count(),
            'repairs_applied' => 0,
            'channels_with_existing_programmes' => 0,
            'detailed_apply_logs' => 0,
            'preview_channels' => [],
            'preview_truncated' => false,
            'issue_breakdown' => [],
            'decision_breakdown' => [],
            'confidence_breakdown' => [],
            'apply_outcome_breakdown' => [],
            'report_path' => null,
            'report_filename' => null,
            'report_rows_written' => 0,
        ], 'apply-reviewed');

        $appliedChannels = [];

        foreach ($approved as $candidate) {
            $item = $this->candidateItemFromRow($candidate);
            $checkpoint['channels_scanned']++;
            $checkpoint['last_channel_id'] = $item['channel_id'];
            $checkpoint['channels_with_existing_programmes'] += filled($item['current_epg_channel_id']) ? 1 : 0;

            $channel = Channel::query()->with(['epgChannel.epg'])->find($item['channel_id']);
            $applyOutcome = 'preview_only';

            if (! $channel) {
                $applyOutcome = 'channel_missing';
            } elseif (! $this->isReviewStillCurrent($channel, $item)) {
                $applyOutcome = 'stale_review';
            } elseif (! EpgChannel::query()->whereKey($item['suggested_epg_channel_id'])->exists()) {
                $applyOutcome = 'suggested_channel_missing';
            } else {
                Channel::query()
                    ->whereKey($channel->id)
                    ->update([
                        'epg_channel_id' => $item['suggested_epg_channel_id'],
                    ]);

                $applyOutcome = 'applied';
                $checkpoint['repairs_applied']++;
                $appliedChannels[(string) $item['channel_id']] = $applyOutcome;
            }

            $item['applied'] = $applyOutcome === 'applied';
            $item['apply_outcome'] = $applyOutcome;
            $item['review_status'] = $applyOutcome === 'applied' ? 'applied' : ($candidate->review_status ?? 'approved');
            $item['last_apply_run_id'] = $applyOutcome === 'applied' ? $context->run->id : null;

            $this->recordPreviewItem($checkpoint, $item);
            $this->persistCandidate($context->run, $item, $sourceRun->id);
            $this->incrementBreakdown($checkpoint, 'issue_breakdown', $item['issue'] ?? 'reviewed_candidate');
            $this->incrementBreakdown($checkpoint, 'decision_breakdown', $item['decision'] ?? 'reviewed_candidate');
            $this->incrementBreakdown($checkpoint, 'confidence_breakdown', $item['confidence_band'] ?? 'none');
            $this->incrementBreakdown($checkpoint, 'apply_outcome_breakdown', $applyOutcome);
            $this->appendReportRow($checkpoint, $item);

            if ($applyOutcome === 'applied') {
                $context->info('Applied approved repair candidate.', [
                    'channel_id' => $item['channel_id'],
                    'channel_name' => $item['channel_name'] ?? null,
                    'suggested_epg_channel_id' => $item['suggested_epg_channel_id'],
                ]);
            }
        }

        $this->syncSourceReviewStatuses($sourceRun, $appliedChannels, $context->run->id);

        $report = [
            'playlist' => $playlist,
            'epg' => $epg,
            'progress' => 100,
            'totals' => [
                'channels_scanned' => $checkpoint['channels_scanned'],
                'issues_found' => $checkpoint['issues_found'],
                'repair_candidates' => $checkpoint['repair_candidates'],
                'repairs_applied' => $checkpoint['repairs_applied'],
                'epg_channels_available' => $checkpoint['epg_channels_available'],
                'channels_with_existing_programmes' => $checkpoint['channels_with_existing_programmes'],
                'compared_epg_sources' => $checkpoint['compared_epg_sources'],
                'cross_source_candidates' => $checkpoint['cross_source_candidates'],
            ],
            'issue_breakdown' => $checkpoint['issue_breakdown'],
            'decision_breakdown' => $checkpoint['decision_breakdown'],
            'confidence_breakdown' => $checkpoint['confidence_breakdown'],
            'apply_outcome_breakdown' => $checkpoint['apply_outcome_breakdown'],
            'channels' => $checkpoint['preview_channels'],
            'source_scope' => $checkpoint['source_scope'],
            'report' => [
                'path' => $checkpoint['report_path'],
                'filename' => $checkpoint['report_filename'],
                'rows_written' => $checkpoint['report_rows_written'],
            ],
        ];

        return PluginActionResult::success(
            sprintf(
                'Applied %d reviewed repair(s) from scan #%d.',
                $checkpoint['repairs_applied'],
                $sourceRun->id,
            ),
            [
                'dry_run' => false,
                'source_run_id' => $sourceRun->id,
                ...$this->resultSnapshot($report, $context->run),
            ],
        );
    }

    private function processRepairStream(
        Playlist $playlist,
        Epg $epg,
        int $hoursAhead,
        float $threshold,
        PluginExecutionContext $context,
        bool $applyRepairs,
        string $sourceScope,
        array $applyOptions,
    ): array
    {
        $mode = $applyRepairs ? 'apply' : 'scan';
        $totalChannels = (int) $playlist->enabled_live_channels()->count();
        $epgChannelsAvailable = (int) $epg->channels()->count();
        $comparisonEpgs = $this->comparisonEpgs($playlist, $epg, $sourceScope);
        $checkpoint = $this->initialCheckpointState(
            playlist: $playlist,
            epg: $epg,
            hoursAhead: $hoursAhead,
            threshold: $threshold,
            totalChannels: $totalChannels,
            epgChannelsAvailable: $epgChannelsAvailable,
            context: $context,
            mode: $mode,
            sourceScope: $sourceScope,
            applyOptions: $applyOptions,
            comparedEpgSources: $comparisonEpgs->count(),
        );
        $checkpoint = $this->prepareReportState($context, $checkpoint, $mode);

        $start = Carbon::now();
        $end = $start->copy()->addHours($hoursAhead);
        $chunkNumber = 0;
        $detailedApplyLogs = (int) ($checkpoint['detailed_apply_logs'] ?? 0);
        $cancelled = false;

        $query = $playlist->enabled_live_channels()
            ->with(['epgChannel.epg'])
            ->orderBy('channels.id');

        if (($checkpoint['last_channel_id'] ?? null) !== null) {
            $query->where('channels.id', '>', $checkpoint['last_channel_id']);
        }

        $query->chunkById(self::SCAN_CHUNK_SIZE, function (Collection $channels) use (
            $applyRepairs,
            $applyOptions,
            $comparisonEpgs,
            $context,
            $epg,
            $playlist,
            $end,
            &$cancelled,
            &$checkpoint,
            &$chunkNumber,
            &$detailedApplyLogs,
            $start,
            $sourceScope,
            $threshold
        ): bool {
            if ($context->cancellationRequested()) {
                $cancelled = true;
                $context->checkpoint(
                    progress: $this->progressPercent($checkpoint['channels_scanned'], $checkpoint['total_channels']),
                    message: 'Cancellation requested. Saving the last safe checkpoint.',
                    state: ['epg_repair' => $checkpoint],
                    log: true,
                );

                return false;
            }

            $chunkNumber++;

            $mappedChannelIds = $channels
                ->filter(fn (Channel $channel) => $channel->epgChannel?->epg_id === $epg->id && filled($channel->epgChannel?->channel_id))
                ->map(fn (Channel $channel) => $channel->epgChannel->channel_id)
                ->unique()
                ->values()
                ->all();

            $programmes = $mappedChannelIds === []
                ? []
                : $this->cacheService->getCachedProgrammesRange(
                    $epg,
                    $start->toDateString(),
                    $end->toDateString(),
                    $mappedChannelIds,
                );

            foreach ($channels as $channel) {
                $checkpoint['channels_scanned']++;
                $checkpoint['last_channel_id'] = $channel->id;

                if ($channel->epgChannel?->epg_id === $epg->id && filled($channel->epgChannel?->channel_id)) {
                    $checkpoint['channels_with_existing_programmes']++;
                }

                $issue = $this->detectIssue($channel, $epg, $programmes);
                if (! $issue) {
                    continue;
                }

                $checkpoint['issues_found']++;
                $this->incrementBreakdown($checkpoint, 'issue_breakdown', $issue);

                $comparison = $this->compareSuggestions(
                    channel: $channel,
                    selectedEpg: $epg,
                    candidateEpgs: $comparisonEpgs,
                    threshold: $threshold,
                    sourceScope: $sourceScope,
                );
                $suggested = $comparison['best_match'];
                $bestCandidate = $comparison['best_candidate'];
                $matchInsight = $comparison['match_insight'];
                $confidence = $matchInsight['confidence'];
                $repairable = $matchInsight['repairable'];
                $analysisDecision = $this->analysisDecisionForItem($comparison, $repairable, $sourceScope, $epg->id);

                if ($repairable) {
                    $checkpoint['repair_candidates']++;
                }

                $item = [
                    'channel_id' => $channel->id,
                    'channel_name' => $channel->title_custom ?? $channel->title ?? $channel->name_custom ?? $channel->name,
                    'playlist_id' => $playlist->id,
                    'playlist_name' => $playlist->name,
                    'issue' => $issue,
                    'current_epg_channel_id' => $channel->epg_channel_id,
                    'current_epg_channel_name' => $channel->epgChannel?->display_name ?? $channel->epgChannel?->name ?? $channel->epgChannel?->channel_id,
                    'current_epg_source_id' => $channel->epgChannel?->epg_id,
                    'current_epg_source_name' => $channel->epgChannel?->epg?->name,
                    'suggested_epg_channel_id' => $suggested?->id,
                    'suggested_epg_channel_name' => $suggested?->display_name ?? $suggested?->name ?? $suggested?->channel_id,
                    'suggested_epg_source_id' => $bestCandidate['epg_id'] ?? $suggested?->epg_id,
                    'suggested_epg_source_name' => $bestCandidate['epg_name'] ?? $suggested?->epg?->name ?? $epg->name,
                    'confidence' => $confidence,
                    'confidence_band' => $matchInsight['confidence_band'],
                    'match_reason' => $matchInsight['match_reason'],
                    'repairable' => $repairable,
                    'decision' => $analysisDecision,
                    'source_scope' => $sourceScope,
                    'source_candidates' => $comparison['candidates'],
                    'source_candidates_count' => count($comparison['candidates']),
                    'selected_epg_source_id' => $epg->id,
                    'selected_epg_source_name' => $epg->name,
                ];

                $this->incrementBreakdown($checkpoint, 'decision_breakdown', $item['decision']);
                $this->incrementBreakdown($checkpoint, 'confidence_breakdown', $item['confidence_band']);
                if (($comparison['best_match']?->epg_id ?? null) !== null && $comparison['best_match']?->epg_id !== $epg->id) {
                    $checkpoint['cross_source_candidates']++;
                }

                if (! $applyRepairs || ! $repairable) {
                    $item['applied'] = false;
                    $item['apply_outcome'] = $applyRepairs ? 'needs_review' : 'preview_only';
                    $this->recordPreviewItem($checkpoint, $item);
                    $this->persistCandidate($context->run, $item);
                    $this->appendReportRow($checkpoint, $item);
                    $this->incrementBreakdown($checkpoint, 'apply_outcome_breakdown', $item['apply_outcome']);
                    continue;
                }

                [$mayApply, $applyOutcome] = $this->evaluateApplyDecision(
                    item: $item,
                    issue: $issue,
                    applyOptions: $applyOptions,
                    repairsApplied: $checkpoint['repairs_applied'],
                    selectedEpgId: $epg->id,
                );

                if (! $mayApply) {
                    $item['applied'] = false;
                    $item['apply_outcome'] = $applyOutcome;
                    $this->recordPreviewItem($checkpoint, $item);
                    $this->persistCandidate($context->run, $item);
                    $this->appendReportRow($checkpoint, $item);
                    $this->incrementBreakdown($checkpoint, 'apply_outcome_breakdown', $item['apply_outcome']);
                    continue;
                }

                Channel::query()
                    ->whereKey($channel->id)
                    ->update([
                        'epg_channel_id' => $item['suggested_epg_channel_id'],
                    ]);

                $checkpoint['repairs_applied']++;
                $item['applied'] = true;
                $item['apply_outcome'] = 'applied';
                $item['review_status'] = 'applied';
                $item['last_apply_run_id'] = $context->run->id;
                $this->recordPreviewItem($checkpoint, $item);
                $this->persistCandidate($context->run, $item);
                $this->appendReportRow($checkpoint, $item);
                $this->incrementBreakdown($checkpoint, 'apply_outcome_breakdown', $item['apply_outcome']);

                if ($detailedApplyLogs < self::MAX_DETAILED_APPLY_LOGS) {
                    $context->info('Applied EPG repair to channel.', [
                        'channel_id' => $item['channel_id'],
                        'channel_name' => $item['channel_name'],
                        'suggested_epg_channel_id' => $item['suggested_epg_channel_id'],
                        'suggested_epg_channel_name' => $item['suggested_epg_channel_name'],
                        'confidence' => $item['confidence'],
                    ]);
                    $detailedApplyLogs++;
                    $checkpoint['detailed_apply_logs'] = $detailedApplyLogs;
                }

                continue;
            }

            $progress = $this->progressPercent($checkpoint['channels_scanned'], $checkpoint['total_channels']);
            $state = ['epg_repair' => $checkpoint];

            if ($chunkNumber % self::CHECKPOINT_EVERY_CHUNKS === 0) {
                $context->checkpoint(
                    progress: $progress,
                    message: $this->chunkMessage($applyRepairs, $checkpoint),
                    state: $state,
                    log: true,
                    context: [
                        'channels_scanned' => $checkpoint['channels_scanned'],
                        'issues_found' => $checkpoint['issues_found'],
                        'repair_candidates' => $checkpoint['repair_candidates'],
                        'repairs_applied' => $checkpoint['repairs_applied'],
                    ],
                );
            } else {
                $context->heartbeat(
                    message: $this->chunkMessage($applyRepairs, $checkpoint),
                    progress: $progress,
                    state: $state,
                );
            }

            if ($context->cancellationRequested()) {
                $cancelled = true;
                $context->checkpoint(
                    progress: $progress,
                    message: 'Cancellation requested. Saving the last safe checkpoint.',
                    state: $state,
                    log: true,
                );

                return false;
            }

            return true;
        }, 'channels.id', 'id');

        return [[
            'playlist' => [
                'id' => $playlist->id,
                'name' => $playlist->name,
            ],
            'epg' => [
                'id' => $epg->id,
                'name' => $epg->name,
            ],
            'progress' => $cancelled ? $this->progressPercent($checkpoint['channels_scanned'], $checkpoint['total_channels']) : 100,
            'totals' => [
                'channels_scanned' => $checkpoint['channels_scanned'],
                'issues_found' => $checkpoint['issues_found'],
                'repair_candidates' => $checkpoint['repair_candidates'],
                'repairs_applied' => $checkpoint['repairs_applied'],
                'epg_channels_available' => $checkpoint['epg_channels_available'],
                'channels_with_existing_programmes' => $checkpoint['channels_with_existing_programmes'],
                'compared_epg_sources' => $checkpoint['compared_epg_sources'],
                'cross_source_candidates' => $checkpoint['cross_source_candidates'],
            ],
            'issue_breakdown' => $checkpoint['issue_breakdown'],
            'decision_breakdown' => $checkpoint['decision_breakdown'],
            'confidence_breakdown' => $checkpoint['confidence_breakdown'],
            'apply_outcome_breakdown' => $checkpoint['apply_outcome_breakdown'],
            'channels' => $checkpoint['preview_channels'],
            'source_scope' => $checkpoint['source_scope'],
            'review' => $this->reviewSummaryForRun($context->run, $checkpoint['preview_channels']),
            'report' => [
                'path' => $checkpoint['report_path'],
                'filename' => $checkpoint['report_filename'],
                'rows_written' => $checkpoint['report_rows_written'],
            ],
        ], $cancelled];
    }

    private function resolveTargets(array $payload, array $settings): array
    {
        $playlistId = $payload['playlist_id'] ?? $settings['default_playlist_id'] ?? null;
        $epgId = $payload['epg_id'] ?? $settings['default_epg_id'] ?? null;

        $playlist = $playlistId ? Playlist::find($playlistId) : null;
        $epg = $epgId ? Epg::find($epgId) : null;

        return [$playlist, $epg];
    }

    private function detectIssue(Channel $channel, Epg $epg, array $programmes): ?string
    {
        if ($channel->epg_map_enabled === false) {
            return 'mapping_disabled';
        }

        if (! $channel->epg_channel_id) {
            return 'unmapped';
        }

        if (! $channel->epgChannel) {
            return 'mapped_channel_missing';
        }

        if (blank($channel->epgChannel->channel_id)) {
            return 'mapped_without_source_key';
        }

        if ($channel->epgChannel->epg_id !== $epg->id) {
            return 'mapped_to_other_epg';
        }

        $channelKey = $channel->epgChannel->channel_id;
        if ($channelKey && empty($programmes[$channelKey] ?? [])) {
            return 'mapped_without_upcoming_programmes';
        }

        return null;
    }

    private function initialCheckpointState(
        Playlist $playlist,
        Epg $epg,
        int $hoursAhead,
        float $threshold,
        int $totalChannels,
        int $epgChannelsAvailable,
        PluginExecutionContext $context,
        string $mode,
        string $sourceScope,
        array $applyOptions,
        int $comparedEpgSources,
    ): array {
        $state = $context->state('epg_repair', []);

        $canResume = ($state['mode'] ?? null) === $mode
            && ($state['playlist_id'] ?? null) === $playlist->id
            && ($state['epg_id'] ?? null) === $epg->id
            && (int) ($state['hours_ahead'] ?? 0) === $hoursAhead
            && (float) ($state['confidence_threshold'] ?? 0) === $threshold
            && ($state['source_scope'] ?? 'selected_only') === $sourceScope
            && ($state['apply_scope'] ?? 'safe_only') === ($applyOptions['apply_scope'] ?? 'safe_only')
            && (bool) ($state['allow_source_switch'] ?? false) === (bool) ($applyOptions['allow_source_switch'] ?? false)
            && (int) ($state['max_repairs'] ?? 250) === (int) ($applyOptions['max_repairs'] ?? 250);

        if (! $canResume) {
            return [
                'mode' => $mode,
                'playlist_id' => $playlist->id,
                'epg_id' => $epg->id,
                'hours_ahead' => $hoursAhead,
                'confidence_threshold' => $threshold,
                'source_scope' => $sourceScope,
                'apply_scope' => $applyOptions['apply_scope'] ?? 'safe_only',
                'allow_source_switch' => (bool) ($applyOptions['allow_source_switch'] ?? false),
                'max_repairs' => (int) ($applyOptions['max_repairs'] ?? 250),
                'total_channels' => $totalChannels,
                'epg_channels_available' => $epgChannelsAvailable,
                'compared_epg_sources' => $comparedEpgSources,
                'cross_source_candidates' => 0,
                'last_channel_id' => null,
                'channels_scanned' => 0,
                'issues_found' => 0,
                'repair_candidates' => 0,
                'repairs_applied' => 0,
                'channels_with_existing_programmes' => 0,
                'detailed_apply_logs' => 0,
                'preview_channels' => [],
                'preview_truncated' => false,
                'issue_breakdown' => [],
                'decision_breakdown' => [],
                'confidence_breakdown' => [],
                'apply_outcome_breakdown' => [],
                'report_path' => null,
                'report_filename' => null,
                'report_rows_written' => 0,
            ];
        }

        $state['total_channels'] = $totalChannels;
        $state['epg_channels_available'] = $epgChannelsAvailable;
        $state['compared_epg_sources'] = $comparedEpgSources;
        $state['preview_channels'] = $state['preview_channels'] ?? [];
        $state['preview_truncated'] = (bool) ($state['preview_truncated'] ?? false);
        $state['issue_breakdown'] = $state['issue_breakdown'] ?? [];
        $state['decision_breakdown'] = $state['decision_breakdown'] ?? [];
        $state['confidence_breakdown'] = $state['confidence_breakdown'] ?? [];
        $state['apply_outcome_breakdown'] = $state['apply_outcome_breakdown'] ?? [];
        $state['repairs_applied'] = (int) ($state['repairs_applied'] ?? 0);
        $state['detailed_apply_logs'] = (int) ($state['detailed_apply_logs'] ?? 0);
        $state['cross_source_candidates'] = (int) ($state['cross_source_candidates'] ?? 0);
        $state['report_path'] = $state['report_path'] ?? null;
        $state['report_filename'] = $state['report_filename'] ?? null;
        $state['report_rows_written'] = (int) ($state['report_rows_written'] ?? 0);

        return $state;
    }

    private function chunkMessage(bool $applyRepairs, array $checkpoint): string
    {
        $prefix = $applyRepairs ? 'Applying repairs' : 'Scanning channels';

        return sprintf(
            '%s: %d/%d channels checked, %d issue(s), %d repair candidate(s), %d applied.',
            $prefix,
            $checkpoint['channels_scanned'],
            $checkpoint['total_channels'],
            $checkpoint['issues_found'],
            $checkpoint['repair_candidates'],
            $checkpoint['repairs_applied'],
        );
    }

    private function progressPercent(int $processed, int $total): int
    {
        if ($total <= 0) {
            return 100;
        }

        return min(99, (int) floor(($processed / $total) * 100));
    }

    private function prepareReportState(PluginExecutionContext $context, array $checkpoint, string $mode): array
    {
        $disk = Storage::disk('local');
        $reportPath = $checkpoint['report_path'] ?? null;

        if ($reportPath && $disk->exists($reportPath)) {
            return $checkpoint;
        }

        $directory = 'plugin-reports/'.$context->plugin->plugin_id;
        $disk->makeDirectory($directory);

        $filename = Str::slug($context->plugin->plugin_id.'-'.$mode.'-run-'.$context->run->id).'.csv';
        $reportPath = $directory.'/'.$filename;

        $disk->put($reportPath, $this->csvRow([
            'channel_id',
            'channel_name',
            'playlist_name',
            'issue',
            'decision',
            'current_epg_channel_id',
            'current_epg_channel_name',
            'current_epg_source_name',
            'suggested_epg_channel_id',
            'suggested_epg_channel_name',
            'suggested_epg_source_name',
            'source_scope',
            'confidence',
            'confidence_band',
            'match_reason',
            'source_candidates_summary',
            'apply_outcome',
            'repairable',
            'applied',
        ]).PHP_EOL);

        $checkpoint['report_path'] = $reportPath;
        $checkpoint['report_filename'] = $filename;
        $checkpoint['report_rows_written'] = 0;

        return $checkpoint;
    }

    private function appendReportRow(array &$checkpoint, array $item): void
    {
        if (! ($checkpoint['report_path'] ?? null)) {
            return;
        }

        Storage::disk('local')->append($checkpoint['report_path'], $this->csvRow([
            $item['channel_id'],
            $item['channel_name'],
            $item['playlist_name'],
            $item['issue'],
            $item['decision'],
            $item['current_epg_channel_id'],
            $item['current_epg_channel_name'],
            $item['current_epg_source_name'],
            $item['suggested_epg_channel_id'],
            $item['suggested_epg_channel_name'],
            $item['suggested_epg_source_name'],
            $item['source_scope'] ?? 'selected_only',
            $item['confidence'],
            $item['confidence_band'],
            $item['match_reason'],
            $this->sourceCandidatesSummary($item['source_candidates'] ?? []),
            $item['apply_outcome'] ?? 'preview_only',
            $item['repairable'] ? 'yes' : 'no',
            ($item['applied'] ?? false) ? 'yes' : 'no',
        ]));

        $checkpoint['report_rows_written'] = (int) ($checkpoint['report_rows_written'] ?? 0) + 1;
    }

    private function csvRow(array $columns): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $columns, ',', '"', '');
        rewind($stream);
        $csv = stream_get_contents($stream) ?: '';
        fclose($stream);

        return rtrim($csv, "\r\n");
    }

    private function sourceCandidatesSummary(array $candidates): string
    {
        return collect($candidates)
            ->map(fn (array $candidate) => sprintf(
                '%s:%s:%s',
                $candidate['epg_name'] ?? 'unknown',
                $candidate['epg_channel_name'] ?? 'unknown',
                $candidate['confidence_band'] ?? 'none',
            ))
            ->implode(' | ');
    }

    private function resultSnapshot(array $report, ?ExtensionPluginRun $run = null): array
    {
        $channels = $report['channels'] ?? [];
        $preview = array_slice($channels, 0, self::MAX_RESULT_CHANNELS);
        $review = $run ? $this->reviewSummaryForRun($run, $preview) : ($report['review'] ?? $this->initialReviewState($preview));

        return [
            'progress' => $report['progress'] ?? 100,
            'playlist' => $report['playlist'] ?? null,
            'epg' => $report['epg'] ?? null,
            'source_scope' => $report['source_scope'] ?? 'selected_only',
            'totals' => $report['totals'] ?? [],
            'issue_breakdown' => $report['issue_breakdown'] ?? [],
            'decision_breakdown' => $report['decision_breakdown'] ?? [],
            'confidence_breakdown' => $report['confidence_breakdown'] ?? [],
            'apply_outcome_breakdown' => $report['apply_outcome_breakdown'] ?? [],
            'channels_preview' => $preview,
            'channels_preview_count' => count($preview),
            'channels_total_count' => data_get($report, 'totals.issues_found', count($channels)),
            'channels_truncated' => (bool) data_get($report, 'totals.issues_found', count($channels)) > count($preview),
            'review' => $review,
            'report' => $report['report'] ?? null,
        ];
    }

    private function initialReviewState(array $previewChannels): array
    {
        $pending = collect($previewChannels)
            ->filter(fn (array $item): bool => (bool) ($item['repairable'] ?? false) && filled($item['suggested_epg_channel_id'] ?? null))
            ->count();

        return [
            'decisions' => [],
            'counts' => [
                'approved' => 0,
                'rejected' => 0,
                'applied' => 0,
                'pending' => $pending,
            ],
        ];
    }

    private function incrementBreakdown(array &$checkpoint, string $key, string $bucket): void
    {
        if ($bucket === '') {
            return;
        }

        $checkpoint[$key] ??= [];
        $checkpoint[$key][$bucket] = (int) ($checkpoint[$key][$bucket] ?? 0) + 1;
    }

    private function recordPreviewItem(array &$checkpoint, array $item): void
    {
        $checkpoint['preview_channels'] ??= [];

        foreach ($checkpoint['preview_channels'] ?? [] as $index => $previewItem) {
            if (($previewItem['channel_id'] ?? null) !== ($item['channel_id'] ?? null)) {
                continue;
            }

            $checkpoint['preview_channels'][$index] = $item;

            return;
        }

        if (count($checkpoint['preview_channels']) < self::MAX_RESULT_CHANNELS) {
            $checkpoint['preview_channels'][] = $item;

            return;
        }

        $checkpoint['preview_truncated'] = true;
    }

    private function isReviewStillCurrent(Channel $channel, array $item): bool
    {
        $currentSourceId = $channel->epgChannel?->epg_id;

        return (int) ($channel->epg_channel_id ?? 0) === (int) ($item['current_epg_channel_id'] ?? 0)
            && (int) ($currentSourceId ?? 0) === (int) ($item['current_epg_source_id'] ?? 0);
    }

    private function syncSourceReviewStatuses(ExtensionPluginRun $sourceRun, array $appliedChannels, int $applyRunId): void
    {
        if ($appliedChannels === []) {
            return;
        }

        foreach ($appliedChannels as $channelId => $applyOutcome) {
            $candidate = $sourceRun->epgRepairCandidates()
                ->where('channel_id', (int) $channelId)
                ->first();

            if (! $candidate) {
                continue;
            }

            $candidate->forceFill([
                'review_status' => $applyOutcome === 'applied' ? 'applied' : $candidate->review_status,
                'apply_outcome' => $applyOutcome,
                'applied' => $applyOutcome === 'applied',
                'last_apply_run_id' => $applyRunId,
                'reviewed_at' => now(),
            ])->save();
        }

        $this->syncRunReviewSnapshotFromCandidates($sourceRun);
    }

    private function persistCandidate(ExtensionPluginRun $run, array $item, ?int $sourceRunId = null): void
    {
        PluginEpgRepairScanCandidate::query()->updateOrCreate(
            [
                'extension_plugin_run_id' => $run->id,
                'channel_id' => (int) $item['channel_id'],
            ],
            $this->candidateRowPayload($run, $item, $sourceRunId),
        );
    }

    private function candidateRowPayload(ExtensionPluginRun $run, array $item, ?int $sourceRunId = null): array
    {
        $reviewStatus = $item['review_status']
            ?? (($item['applied'] ?? false) ? 'applied' : ((bool) ($item['repairable'] ?? false) && filled($item['suggested_epg_channel_id'] ?? null) ? 'pending' : 'pending'));

        return [
            'source_run_id' => $sourceRunId,
            'playlist_id' => $item['playlist_id'] ?? null,
            'playlist_name' => $item['playlist_name'] ?? null,
            'issue' => $item['issue'] ?? null,
            'decision' => $item['decision'] ?? null,
            'current_epg_channel_id' => $item['current_epg_channel_id'] ?? null,
            'current_epg_channel_name' => $item['current_epg_channel_name'] ?? null,
            'current_epg_source_id' => $item['current_epg_source_id'] ?? null,
            'current_epg_source_name' => $item['current_epg_source_name'] ?? null,
            'suggested_epg_channel_id' => $item['suggested_epg_channel_id'] ?? null,
            'suggested_epg_channel_name' => $item['suggested_epg_channel_name'] ?? null,
            'suggested_epg_source_id' => $item['suggested_epg_source_id'] ?? null,
            'suggested_epg_source_name' => $item['suggested_epg_source_name'] ?? null,
            'selected_epg_source_id' => $item['selected_epg_source_id'] ?? null,
            'selected_epg_source_name' => $item['selected_epg_source_name'] ?? null,
            'source_scope' => $item['source_scope'] ?? 'selected_only',
            'confidence' => $item['confidence'] ?? null,
            'confidence_band' => $item['confidence_band'] ?? null,
            'match_reason' => $item['match_reason'] ?? null,
            'repairable' => (bool) ($item['repairable'] ?? false),
            'source_candidates' => $item['source_candidates'] ?? [],
            'apply_outcome' => $item['apply_outcome'] ?? null,
            'applied' => (bool) ($item['applied'] ?? false),
            'review_status' => $reviewStatus,
            'reviewed_by_user_id' => $item['reviewed_by_user_id'] ?? null,
            'reviewed_by_user_name' => $item['reviewed_by_user_name'] ?? null,
            'reviewed_at' => $item['reviewed_at'] ?? (($reviewStatus !== 'pending' || ($item['applied'] ?? false)) ? now() : null),
            'last_apply_run_id' => $item['last_apply_run_id'] ?? null,
        ];
    }

    private function candidateItemFromRow(PluginEpgRepairScanCandidate $candidate): array
    {
        return [
            'channel_id' => $candidate->channel_id,
            'channel_name' => $candidate->channel?->title_custom ?? $candidate->channel?->title ?? $candidate->channel?->name_custom ?? $candidate->channel?->name,
            'playlist_id' => $candidate->playlist_id,
            'playlist_name' => $candidate->playlist_name,
            'issue' => $candidate->issue,
            'decision' => $candidate->decision,
            'current_epg_channel_id' => $candidate->current_epg_channel_id,
            'current_epg_channel_name' => $candidate->current_epg_channel_name,
            'current_epg_source_id' => $candidate->current_epg_source_id,
            'current_epg_source_name' => $candidate->current_epg_source_name,
            'suggested_epg_channel_id' => $candidate->suggested_epg_channel_id,
            'suggested_epg_channel_name' => $candidate->suggested_epg_channel_name,
            'suggested_epg_source_id' => $candidate->suggested_epg_source_id,
            'suggested_epg_source_name' => $candidate->suggested_epg_source_name,
            'selected_epg_source_id' => $candidate->selected_epg_source_id,
            'selected_epg_source_name' => $candidate->selected_epg_source_name,
            'source_scope' => $candidate->source_scope,
            'confidence' => $candidate->confidence,
            'confidence_band' => $candidate->confidence_band,
            'match_reason' => $candidate->match_reason,
            'repairable' => $candidate->repairable,
            'source_candidates' => $candidate->source_candidates ?? [],
            'source_candidates_count' => count($candidate->source_candidates ?? []),
            'apply_outcome' => $candidate->apply_outcome,
            'applied' => $candidate->applied,
            'review_status' => $candidate->review_status,
            'reviewed_by_user_id' => $candidate->reviewed_by_user_id,
            'reviewed_by_user_name' => $candidate->reviewed_by_user_name,
            'reviewed_at' => optional($candidate->reviewed_at)?->toIso8601String(),
            'last_apply_run_id' => $candidate->last_apply_run_id,
        ];
    }

    private function reviewSummaryForRun(ExtensionPluginRun $run, array $previewChannels = []): array
    {
        $reviewableQuery = $run->epgRepairCandidates()
            ->where('repairable', true)
            ->whereNotNull('suggested_epg_channel_id');

        $counts = [
            'approved' => (clone $reviewableQuery)->where('review_status', 'approved')->count(),
            'rejected' => (clone $reviewableQuery)->where('review_status', 'rejected')->count(),
            'applied' => (clone $reviewableQuery)->where('review_status', 'applied')->count(),
            'pending' => (clone $reviewableQuery)->where('review_status', 'pending')->count(),
        ];

        $previewIds = collect($previewChannels)
            ->pluck('channel_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $decisions = [];

        if ($previewIds !== []) {
            $decisions = $run->epgRepairCandidates()
                ->whereIn('channel_id', $previewIds)
                ->get()
                ->mapWithKeys(fn (PluginEpgRepairScanCandidate $candidate): array => [
                    (string) $candidate->channel_id => $this->decisionPayloadFromCandidate($candidate),
                ])
                ->all();
        }

        return [
            'decisions' => $decisions,
            'counts' => $counts,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    private function decisionPayloadFromCandidate(PluginEpgRepairScanCandidate $candidate): array
    {
        return [
            'status' => $candidate->review_status ?? 'pending',
            'updated_at' => optional($candidate->reviewed_at)->toIso8601String(),
            'user_id' => $candidate->reviewed_by_user_id,
            'user_name' => $candidate->reviewed_by_user_name,
            'last_apply_outcome' => $candidate->apply_outcome,
            'last_apply_run_id' => $candidate->last_apply_run_id,
            'item' => $this->candidateItemFromRow($candidate),
        ];
    }

    private function syncRunReviewSnapshotFromCandidates(ExtensionPluginRun $run): void
    {
        $result = $run->result ?? [];
        $data = $result['data'] ?? [];
        $previewChannels = $data['channels_preview'] ?? [];
        $data['review'] = $this->reviewSummaryForRun($run, $previewChannels);
        $result['data'] = $data;

        $run->forceFill(['result' => $result])->save();
    }

    private function emptyMatchInsight(): array
    {
        return [
            'confidence' => null,
            'confidence_band' => 'none',
            'match_reason' => 'no_candidate',
            'repairable' => false,
        ];
    }

    private function analysisDecisionForItem(array $comparison, bool $repairable, string $sourceScope, int $selectedEpgId): string
    {
        $suggested = $comparison['best_match'];
        if (! $suggested) {
            return 'no_candidate';
        }

        if (! $repairable) {
            return 'needs_review';
        }

        if ($sourceScope === 'all_owned' && $suggested->epg_id !== $selectedEpgId) {
            return 'better_source_available';
        }

        return 'repairable';
    }

    private function comparisonEpgs(Playlist $playlist, Epg $selectedEpg, string $sourceScope): Collection
    {
        if ($sourceScope !== 'all_owned') {
            return collect([$selectedEpg]);
        }

        $epgs = Epg::query()
            ->where('user_id', $playlist->user_id)
            ->where('status', Status::Completed)
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$selectedEpg->id])
            ->orderBy('name')
            ->get();

        return $epgs->isEmpty() ? collect([$selectedEpg]) : $epgs;
    }

    private function compareSuggestions(
        Channel $channel,
        Epg $selectedEpg,
        Collection $candidateEpgs,
        float $threshold,
        string $sourceScope,
    ): array {
        $candidates = $candidateEpgs
            ->map(function (Epg $candidateEpg) use ($channel, $selectedEpg, $threshold) {
                $match = $this->similaritySearch->findMatchingEpgChannel($channel, $candidateEpg);
                if (! $match) {
                    return null;
                }

                $insight = $this->matchInsight($channel, $match, $threshold);

                return [
                    'epg_id' => $candidateEpg->id,
                    'epg_name' => $candidateEpg->name,
                    'epg_selected' => $candidateEpg->id === $selectedEpg->id,
                    'epg_channel_id' => $match->id,
                    'epg_channel_name' => $match->display_name ?? $match->name ?? $match->channel_id,
                    'confidence' => $insight['confidence'],
                    'confidence_band' => $insight['confidence_band'],
                    'match_reason' => $insight['match_reason'],
                    'repairable' => $insight['repairable'],
                    'match' => $match,
                    'insight' => $insight,
                ];
            })
            ->filter()
            ->sort(function (array $left, array $right) {
                $confidenceCompare = ($right['confidence'] ?? 0) <=> ($left['confidence'] ?? 0);
                if ($confidenceCompare !== 0) {
                    return $confidenceCompare;
                }

                if ($left['epg_selected'] !== $right['epg_selected']) {
                    return $left['epg_selected'] ? -1 : 1;
                }

                return strcmp((string) $left['epg_name'], (string) $right['epg_name']);
            })
            ->values();

        $best = $candidates->first();

        return [
            'best_match' => $best['match'] ?? null,
            'best_candidate' => $best,
            'match_insight' => $best['insight'] ?? $this->emptyMatchInsight(),
            'candidates' => $candidates
                ->take(self::MAX_SOURCE_CANDIDATES)
                ->map(fn (array $candidate) => [
                    'epg_id' => $candidate['epg_id'],
                    'epg_name' => $candidate['epg_name'],
                    'epg_selected' => $candidate['epg_selected'],
                    'epg_channel_id' => $candidate['epg_channel_id'],
                    'epg_channel_name' => $candidate['epg_channel_name'],
                    'confidence' => $candidate['confidence'],
                    'confidence_band' => $candidate['confidence_band'],
                    'match_reason' => $candidate['match_reason'],
                    'repairable' => $candidate['repairable'],
                ])
                ->all(),
            'compared_all_sources' => $sourceScope === 'all_owned',
        ];
    }

    private function evaluateApplyDecision(
        array $item,
        string $issue,
        array $applyOptions,
        int $repairsApplied,
        int $selectedEpgId,
    ): array {
        $maxRepairs = (int) ($applyOptions['max_repairs'] ?? 250);
        if ($maxRepairs > 0 && $repairsApplied >= $maxRepairs) {
            return [false, 'max_repairs_reached'];
        }

        $scope = $applyOptions['apply_scope'] ?? 'safe_only';
        $allowSourceSwitch = (bool) ($applyOptions['allow_source_switch'] ?? false);

        $allowedIssues = match ($scope) {
            'unmapped_only' => ['unmapped'],
            'broken_only' => ['mapped_channel_missing', 'mapped_without_source_key', 'mapped_without_upcoming_programmes'],
            'all_repairable' => ['unmapped', 'mapped_channel_missing', 'mapped_without_source_key', 'mapped_to_other_epg', 'mapped_without_upcoming_programmes'],
            default => ['unmapped', 'mapped_channel_missing', 'mapped_without_source_key', 'mapped_without_upcoming_programmes'],
        };

        if (! in_array($issue, $allowedIssues, true)) {
            return [false, 'skipped_by_scope'];
        }

        $currentSource = $item['current_epg_source_id'] ?? null;
        $suggestedSource = $item['suggested_epg_source_id'] ?? $selectedEpgId;

        if (! $allowSourceSwitch && $currentSource !== null && $currentSource !== $suggestedSource) {
            return [false, 'source_switch_blocked'];
        }

        if (($item['decision'] ?? null) === 'better_source_available' && ! $allowSourceSwitch) {
            return [false, 'better_source_available'];
        }

        return [true, 'applied'];
    }

    private function matchInsight(Channel $channel, EpgChannel $epgChannel, float $threshold): array
    {
        $channelName = $channel->title_custom ?? $channel->title ?? $channel->name_custom ?? $channel->name;
        $candidateNames = array_filter([
            $epgChannel->display_name,
            $epgChannel->name,
            $epgChannel->channel_id,
        ]);

        $normalizedChannel = $this->normalizer->normalize($channelName);
        if ($normalizedChannel === '') {
            return $this->emptyMatchInsight();
        }

        $best = [
            'confidence' => 0.0,
            'confidence_band' => 'low',
            'match_reason' => 'fuzzy_name_match',
            'repairable' => false,
        ];

        foreach ($candidateNames as $candidateName) {
            $normalizedCandidate = $this->normalizer->normalize($candidateName);
            if ($normalizedCandidate === '') {
                continue;
            }

            $candidate = $this->candidateConfidence($normalizedChannel, $normalizedCandidate);
            if ($candidate['confidence'] > $best['confidence']) {
                $best = $candidate;
            }
        }

        if ($best['confidence'] <= 0) {
            return $this->emptyMatchInsight();
        }

        $best['confidence'] = round($best['confidence'], 4);
        $best['confidence_band'] = $this->confidenceBand($best['confidence']);
        $best['repairable'] = $best['confidence'] >= $threshold;

        return $best;
    }

    private function candidateConfidence(string $normalizedChannel, string $normalizedCandidate): array
    {
        if ($normalizedChannel === $normalizedCandidate) {
            return [
                'confidence' => 1.0,
                'confidence_band' => 'exact',
                'match_reason' => 'exact_normalized_name',
                'repairable' => true,
            ];
        }

        $channelTokens = $this->tokenize($normalizedChannel);
        $candidateTokens = $this->tokenize($normalizedCandidate);

        $intersection = array_values(array_intersect($channelTokens, $candidateTokens));
        $union = array_values(array_unique([...$channelTokens, ...$candidateTokens]));
        $minTokenCount = max(1, min(count($channelTokens), count($candidateTokens)));

        $tokenOverlap = count($intersection) / $minTokenCount;
        $jaccard = count($union) > 0 ? count($intersection) / count($union) : 0.0;

        similar_text($normalizedChannel, $normalizedCandidate, $similarityPercent);
        $similarity = $similarityPercent / 100;

        $distance = levenshtein($normalizedChannel, $normalizedCandidate);
        $length = max(strlen($normalizedChannel), strlen($normalizedCandidate));
        $levenshteinScore = $length > 0 ? max(0, 1 - ($distance / $length)) : 0.0;

        $contains = str_contains($normalizedChannel, $normalizedCandidate) || str_contains($normalizedCandidate, $normalizedChannel);
        $score = ($levenshteinScore * 0.4) + ($similarity * 0.25) + ($tokenOverlap * 0.25) + ($jaccard * 0.1);

        if ($contains && $tokenOverlap >= 0.75) {
            $score = max($score, 0.88);
        }

        if ($tokenOverlap === 1.0 && count($channelTokens) === count($candidateTokens)) {
            $score = max($score, 0.93);
        }

        $channelNumbers = $this->numericTokens($channelTokens);
        $candidateNumbers = $this->numericTokens($candidateTokens);

        if ($channelNumbers !== [] && $candidateNumbers !== [] && array_values($channelNumbers) !== array_values($candidateNumbers)) {
            $score = max(0.0, $score - 0.18);
            $reason = 'number_mismatch_penalty';
        } elseif ($contains && $tokenOverlap >= 0.75) {
            $reason = 'normalized_subset_match';
        } elseif ($tokenOverlap >= 0.8) {
            $reason = 'strong_token_overlap';
        } elseif ($similarity >= 0.75 || $levenshteinScore >= 0.75) {
            $reason = 'fuzzy_name_match';
        } else {
            $reason = 'semantic_or_borderline_match';
        }

        return [
            'confidence' => min(1.0, round($score, 4)),
            'confidence_band' => 'low',
            'match_reason' => $reason,
            'repairable' => false,
        ];
    }

    private function confidenceBand(?float $confidence): string
    {
        if ($confidence === null) {
            return 'none';
        }

        return match (true) {
            $confidence >= 0.98 => 'exact',
            $confidence >= 0.85 => 'high',
            $confidence >= 0.7 => 'medium',
            default => 'low',
        };
    }

    private function tokenize(string $value): array
    {
        return array_values(array_filter(explode(' ', trim($value))));
    }

    private function numericTokens(array $tokens): array
    {
        return array_values(array_filter($tokens, fn (string $token): bool => preg_match('/^\d+$/', $token) === 1));
    }
}
