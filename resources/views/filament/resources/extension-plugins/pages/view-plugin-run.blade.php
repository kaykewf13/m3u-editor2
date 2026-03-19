<x-filament-panels::page>
    @php
        /** @var \App\Models\ExtensionPluginRun $run */
        $run = $this->runRecord;
        $statusColors = [
            'completed' => 'bg-success-50 text-success-700 ring-success-200 dark:bg-success-950/40 dark:text-success-300 dark:ring-success-800',
            'failed' => 'bg-danger-50 text-danger-700 ring-danger-200 dark:bg-danger-950/40 dark:text-danger-300 dark:ring-danger-800',
            'running' => 'bg-warning-50 text-warning-700 ring-warning-200 dark:bg-warning-950/40 dark:text-warning-300 dark:ring-warning-800',
            'cancelled' => 'bg-gray-100 text-gray-700 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800',
            'stale' => 'bg-warning-50 text-warning-700 ring-warning-200 dark:bg-warning-950/40 dark:text-warning-300 dark:ring-warning-800',
        ];
        $statusClass = $statusColors[$run->status] ?? 'bg-gray-50 text-gray-700 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800';
        $payload = json_encode($run->payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $result = json_encode($run->result ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $latestMessage = $this->logs->last()?->message;
        $progress = (int) ($run->progress ?? 0);
        $resultData = data_get($run->result, 'data', []);
        $issueBreakdown = data_get($resultData, 'issue_breakdown', []);
        $decisionBreakdown = data_get($resultData, 'decision_breakdown', []);
        $confidenceBreakdown = data_get($resultData, 'confidence_breakdown', []);
        $report = data_get($resultData, 'report', []);
        $previewChannels = data_get($resultData, 'channels_preview', []);
        $candidateRows = $this->candidateRows;
        $candidateItems = collect($candidateRows->items());
        $playlistTarget = data_get($resultData, 'playlist');
        $epgTarget = data_get($resultData, 'epg');
        $totals = data_get($resultData, 'totals', []);
        $confidenceThreshold = data_get($run->payload, 'confidence_threshold');
        $hoursAhead = data_get($run->payload, 'hours_ahead');
        $sourceScope = data_get($resultData, 'source_scope', data_get($run->payload, 'source_scope', 'selected_only'));
        $applyOutcomeBreakdown = data_get($resultData, 'apply_outcome_breakdown', []);
        $applyScope = data_get($run->payload, 'apply_scope');
        $allowSourceSwitch = data_get($run->payload, 'allow_source_switch');
        $maxRepairs = data_get($run->payload, 'max_repairs');
        $reviewData = data_get($resultData, 'review', []);
        $reviewCounts = data_get($reviewData, 'counts', []);
        $reviewableRun = $this->isReviewableScanRun();
    @endphp

    <div class="space-y-6">
        <section class="overflow-hidden rounded-3xl border border-gray-200/80 bg-gradient-to-br from-white via-primary-50/20 to-white shadow-sm dark:border-gray-800 dark:from-gray-950 dark:via-primary-950/20 dark:to-gray-950">
            <div class="grid gap-6 px-6 py-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(280px,0.8fr)] lg:px-8">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset {{ $statusClass }}">
                            {{ \Illuminate\Support\Str::headline($run->status) }}
                        </span>
                        @if($run->dry_run)
                            <span class="inline-flex items-center rounded-full bg-primary-50 px-3 py-1 text-sm font-medium text-primary-700 ring-1 ring-inset ring-primary-200 dark:bg-primary-950/40 dark:text-primary-300 dark:ring-primary-800">
                                Dry run
                            </span>
                        @endif
                        <span class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800">
                            {{ \Illuminate\Support\Str::headline($run->trigger) }}
                        </span>
                    </div>

                    <div>
                        <p class="text-sm font-medium uppercase tracking-[0.24em] text-primary-600 dark:text-primary-300">Plugin run detail</p>
                        <h2 class="mt-2 text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">
                            {{ $run->plugin?->name ?? 'Unknown plugin' }}
                        </h2>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                            {{ $run->summary ?: 'This run is active, but no summary has been written yet. Use the activity stream below to inspect each step as it happens.' }}
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-2xl border border-gray-200 bg-white/80 p-4 backdrop-blur dark:border-gray-800 dark:bg-gray-900/80">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Operator action</div>
                            <div class="mt-2 text-sm font-medium text-gray-950 dark:text-white">{{ $run->action ? \Illuminate\Support\Str::headline($run->action) : 'Hook-driven run' }}</div>
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $run->hook ? 'Triggered by '.\Illuminate\Support\Str::headline($run->hook) : 'Manually queued from the plugin page' }}</div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-white/80 p-4 backdrop-blur dark:border-gray-800 dark:bg-gray-900/80">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Current signal</div>
                            <div class="mt-2 text-sm font-medium text-gray-950 dark:text-white">{{ $run->progress_message ?: ($latestMessage ?: 'Waiting for activity…') }}</div>
                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                <div class="h-full rounded-full bg-primary-500 transition-all" style="width: {{ max(2, $progress) }}%"></div>
                            </div>
                            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $progress }}% checkpointed progress. The bar advances when the worker writes a heartbeat or saves a checkpoint.</div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-white/80 p-4 backdrop-blur dark:border-gray-800 dark:bg-gray-900/80">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Queued by</div>
                            <div class="mt-2 text-sm font-medium text-gray-950 dark:text-white">{{ $run->user?->name ?? 'System' }}</div>
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ optional($run->created_at)->toDateTimeString() ?: 'Unknown time' }}</div>
                        </div>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                    <div class="rounded-2xl border border-gray-200 bg-white/90 p-5 shadow-xs dark:border-gray-800 dark:bg-gray-900/90">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Lifecycle</div>
                        <dl class="mt-4 space-y-3 text-sm text-gray-600 dark:text-gray-300">
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">Queued</dt>
                                <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ optional($run->created_at)->toDateTimeString() }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">Started</dt>
                                <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ optional($run->started_at)->toDateTimeString() ?? 'Not started' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">Finished</dt>
                                <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ optional($run->finished_at)->toDateTimeString() ?? 'Still running' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">Invocation</dt>
                                <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ \Illuminate\Support\Str::headline($run->invocation_type) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">Last heartbeat</dt>
                                <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ optional($run->last_heartbeat_at)->toDateTimeString() ?? 'No heartbeat yet' }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white/90 p-5 shadow-xs dark:border-gray-800 dark:bg-gray-900/90">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Report snapshot</div>
                        <div class="mt-4 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                            <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-1">
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">Issue mix</div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @forelse($issueBreakdown as $issue => $count)
                                            <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ \Illuminate\Support\Str::headline($issue) }} · {{ $count }}</span>
                                        @empty
                                            <span class="text-xs text-gray-400 dark:text-gray-500">No issue summary yet.</span>
                                        @endforelse
                                    </div>
                                </div>
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">Decisions</div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @forelse($decisionBreakdown as $decision => $count)
                                            <span class="inline-flex rounded-full bg-primary-50 px-2.5 py-1 text-xs font-medium text-primary-700 dark:bg-primary-950/40 dark:text-primary-300">{{ \Illuminate\Support\Str::headline($decision) }} · {{ $count }}</span>
                                        @empty
                                            <span class="text-xs text-gray-400 dark:text-gray-500">No decisions recorded yet.</span>
                                        @endforelse
                                    </div>
                                </div>
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">Confidence</div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @forelse($confidenceBreakdown as $band => $count)
                                            <span class="inline-flex rounded-full bg-success-50 px-2.5 py-1 text-xs font-medium text-success-700 dark:bg-success-950/40 dark:text-success-300">{{ \Illuminate\Support\Str::headline($band) }} · {{ $count }}</span>
                                        @empty
                                            <span class="text-xs text-gray-400 dark:text-gray-500">No confidence summary yet.</span>
                                        @endforelse
                                    </div>
                                </div>
                            </div>
                            @if(filled(data_get($report, 'filename')))
                                <div class="rounded-2xl bg-gray-50 p-4 text-xs text-gray-500 dark:bg-gray-950/60 dark:text-gray-300">
                                    <div class="font-semibold text-gray-700 dark:text-gray-100">Artifact</div>
                                    <div class="mt-1">{{ data_get($report, 'filename') }}</div>
                                    <div class="mt-1">{{ number_format((int) data_get($report, 'rows_written', 0)) }} row(s) written to the CSV report.</div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-[minmax(320px,0.85fr)_minmax(0,1.15fr)]">
            <div class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Target scope</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">This run only inspects the selected playlist against one chosen EPG source at a time.</p>

                <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="rounded-2xl bg-gray-50 p-4 dark:bg-gray-950/60">
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Playlist</dt>
                        <dd class="mt-2 text-sm font-medium text-gray-950 dark:text-white">
                            {{ data_get($playlistTarget, 'name', 'Unknown playlist') }}
                        </dd>
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">ID {{ data_get($playlistTarget, 'id', 'n/a') }}</div>
                    </div>
                    <div class="rounded-2xl bg-gray-50 p-4 dark:bg-gray-950/60">
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">EPG source</dt>
                        <dd class="mt-2 text-sm font-medium text-gray-950 dark:text-white">
                            {{ data_get($epgTarget, 'name', 'Unknown EPG') }}
                        </dd>
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">ID {{ data_get($epgTarget, 'id', 'n/a') }}</div>
                    </div>
                    <div class="rounded-2xl bg-gray-50 p-4 dark:bg-gray-950/60">
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Source comparison</dt>
                        <dd class="mt-2 text-sm font-medium text-gray-950 dark:text-white">
                            {{ \Illuminate\Support\Str::headline((string) str_replace('_', ' ', $sourceScope)) }}
                        </dd>
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ number_format((int) data_get($totals, 'compared_epg_sources', 1)) }} EPG source(s) compared</div>
                    </div>
                    <div class="rounded-2xl bg-gray-50 p-4 dark:bg-gray-950/60">
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Window</dt>
                        <dd class="mt-2 text-sm font-medium text-gray-950 dark:text-white">
                            {{ filled($hoursAhead) ? $hoursAhead.' hours ahead' : 'Not specified' }}
                        </dd>
                    </div>
                    <div class="rounded-2xl bg-gray-50 p-4 dark:bg-gray-950/60">
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Confidence threshold</dt>
                        <dd class="mt-2 text-sm font-medium text-gray-950 dark:text-white">
                            {{ filled($confidenceThreshold) ? $confidenceThreshold : 'Not specified' }}
                        </dd>
                    </div>
                </dl>

                @if(filled($applyScope) || filled($maxRepairs) || ! is_null($allowSourceSwitch))
                    <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div class="rounded-2xl bg-gray-50 p-4 dark:bg-gray-950/60">
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Apply scope</dt>
                            <dd class="mt-2 text-sm font-medium text-gray-950 dark:text-white">
                                {{ filled($applyScope) ? \Illuminate\Support\Str::headline((string) str_replace('_', ' ', $applyScope)) : 'Not specified' }}
                            </dd>
                        </div>
                        <div class="rounded-2xl bg-gray-50 p-4 dark:bg-gray-950/60">
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Source switching</dt>
                            <dd class="mt-2 text-sm font-medium text-gray-950 dark:text-white">
                                {{ is_null($allowSourceSwitch) ? 'Not specified' : ($allowSourceSwitch ? 'Allowed' : 'Blocked') }}
                            </dd>
                        </div>
                        <div class="rounded-2xl bg-gray-50 p-4 dark:bg-gray-950/60">
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Repair cap</dt>
                            <dd class="mt-2 text-sm font-medium text-gray-950 dark:text-white">
                                {{ filled($maxRepairs) ? ((int) $maxRepairs === 0 ? 'Unlimited' : number_format((int) $maxRepairs)) : 'Not specified' }}
                            </dd>
                        </div>
                    </dl>
                @endif

                <div class="mt-5 rounded-2xl border border-dashed border-gray-200 p-4 text-sm text-gray-600 dark:border-gray-800 dark:text-gray-300">
                    <div class="font-medium text-gray-950 dark:text-white">How to read this</div>
                    <p class="mt-2">
                        @if($sourceScope === 'all_owned')
                            This run compares each affected channel against all completed EPG sources you own, then shows the best candidate and the strongest alternatives.
                        @else
                            This run only judges channels against the selected EPG source shown above.
                        @endif
                    </p>
                </div>
            </div>

            <div class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Channel evidence</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            @if($reviewableRun)
                                Review the persisted candidates here, approve the ones you trust, then use <span class="font-medium text-gray-700 dark:text-gray-200">Apply Reviewed</span>.
                            @else
                                This is the stored candidate set for the run, with pagination instead of a truncated preview only.
                            @endif
                        </p>
                    </div>
                    <div class="inline-flex rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                        Showing {{ number_format($candidateRows->firstItem() ?? 0) }}-{{ number_format($candidateRows->lastItem() ?? 0) }} of {{ number_format($candidateRows->total()) }}
                    </div>
                </div>

                @if($reviewableRun)
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach(['approved' => 'bg-success-50 text-success-700 dark:bg-success-950/40 dark:text-success-300', 'rejected' => 'bg-danger-50 text-danger-700 dark:bg-danger-950/40 dark:text-danger-300', 'applied' => 'bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300', 'pending' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200'] as $bucket => $classes)
                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $classes }}">
                                {{ \Illuminate\Support\Str::headline($bucket) }} · {{ (int) ($reviewCounts[$bucket] ?? 0) }}
                            </span>
                        @endforeach
                    </div>
                @endif

                @if($candidateItems->isNotEmpty())
                    <div class="mt-5 overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-800">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                                <thead class="bg-gray-50 text-left text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:bg-gray-950/60 dark:text-gray-400">
                                    <tr>
                                        <th class="px-4 py-3">Channel</th>
                                        <th class="px-4 py-3">Issue</th>
                                        <th class="px-4 py-3">Current mapping</th>
                                        <th class="px-4 py-3">Suggested mapping</th>
                                        <th class="px-4 py-3">Decision</th>
                                        @if($reviewableRun)
                                            <th class="px-4 py-3">Review</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach($candidateItems as $item)
                                        @php
                                            $reviewStatus = $item->review_status ?? 'pending';
                                            $canReviewItem = $reviewableRun && $item->repairable && filled($item->suggested_epg_channel_id);
                                        @endphp
                                        <tr class="align-top">
                                            <td class="px-4 py-4">
                                                <div class="font-medium text-gray-950 dark:text-white">{{ $item->channel?->title_custom ?? $item->channel?->title ?? $item->channel?->name_custom ?? $item->channel?->name ?? 'Unknown channel' }}</div>
                                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Channel ID {{ $item->channel_id ?? 'n/a' }}</div>
                                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Playlist {{ $item->playlist_name ?: data_get($playlistTarget, 'name', 'n/a') }}</div>
                                            </td>
                                            <td class="px-4 py-4">
                                                <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ \Illuminate\Support\Str::headline((string) ($item->issue ?? 'unknown')) }}</span>
                                            </td>
                                            <td class="px-4 py-4">
                                                <div class="font-medium text-gray-950 dark:text-white">{{ $item->current_epg_channel_name ?: 'No current mapping' }}</div>
                                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $item->current_epg_source_name ?: 'No current EPG source' }}</div>
                                                @if(filled($item->current_epg_channel_id))
                                                    <div class="mt-1 text-xs text-gray-400 dark:text-gray-500">EPG Channel ID {{ data_get($item, 'current_epg_channel_id') }}</div>
                                                @endif
                                            </td>
                                            <td class="px-4 py-4">
                                                <div class="font-medium text-gray-950 dark:text-white">{{ $item->suggested_epg_channel_name ?: 'No suggestion' }}</div>
                                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $item->suggested_epg_source_name ?: data_get($epgTarget, 'name', 'Unknown EPG source') }}</div>
                                                <div class="mt-2 flex flex-wrap gap-2 text-xs">
                                                    <span class="inline-flex rounded-full bg-success-50 px-2.5 py-1 font-medium text-success-700 dark:bg-success-950/40 dark:text-success-300">{{ \Illuminate\Support\Str::headline((string) ($item->confidence_band ?? 'none')) }}</span>
                                                    @if(filled($item->confidence))
                                                        <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $item->confidence }}</span>
                                                    @endif
                                                </div>
                                                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::headline((string) ($item->match_reason ?? 'no_match_reason')) }}</div>
                                                @if(count($item->source_candidates ?? []) > 1)
                                                    <div class="mt-3 space-y-1 rounded-xl bg-gray-50 p-3 text-xs text-gray-600 dark:bg-gray-950/60 dark:text-gray-300">
                                                        <div class="font-semibold text-gray-700 dark:text-gray-100">Alternative sources</div>
                                                        @foreach(($item->source_candidates ?? []) as $candidate)
                                                            <div>
                                                                {{ data_get($candidate, 'epg_name') }} → {{ data_get($candidate, 'epg_channel_name') }}
                                                                <span class="text-gray-400 dark:text-gray-500">({{ data_get($candidate, 'confidence_band') }})</span>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="px-4 py-4">
                                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $item->decision === 'repairable' ? 'bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200' }}">
                                                    {{ \Illuminate\Support\Str::headline((string) ($item->decision ?? 'unknown')) }}
                                                </span>
                                                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                                    {{ $item->repairable ? 'The plugin considers this safe enough to apply.' : 'Needs review before any apply run.' }}
                                                </div>
                                                @if(filled($item->apply_outcome))
                                                    <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                                        Apply outcome: {{ \Illuminate\Support\Str::headline((string) $item->apply_outcome) }}
                                                    </div>
                                                @endif
                                                <div class="mt-2 text-xs font-medium {{ $item->applied ? 'text-success-600 dark:text-success-400' : 'text-gray-500 dark:text-gray-400' }}">
                                                    {{ $item->applied ? 'Applied in this run' : 'Stored as review evidence' }}
                                                </div>
                                            </td>
                                            @if($reviewableRun)
                                                <td class="px-4 py-4">
                                                    <div class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium
                                                        @if($reviewStatus === 'approved') bg-success-50 text-success-700 dark:bg-success-950/40 dark:text-success-300
                                                        @elseif($reviewStatus === 'rejected') bg-danger-50 text-danger-700 dark:bg-danger-950/40 dark:text-danger-300
                                                        @elseif($reviewStatus === 'applied') bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300
                                                        @else bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200 @endif">
                                                        {{ \Illuminate\Support\Str::headline((string) $reviewStatus) }}
                                                    </div>

                                                    @if($canReviewItem && $reviewStatus !== 'applied')
                                                        <div class="mt-3 flex flex-wrap gap-2">
                                                            <button
                                                                type="button"
                                                                wire:click="markReviewDecision({{ (int) $item->channel_id }}, 'approved')"
                                                                class="inline-flex items-center rounded-full bg-success-50 px-3 py-1.5 text-xs font-medium text-success-700 transition hover:bg-success-100 dark:bg-success-950/40 dark:text-success-300"
                                                            >
                                                                Approve
                                                            </button>
                                                            <button
                                                                type="button"
                                                                wire:click="markReviewDecision({{ (int) $item->channel_id }}, 'rejected')"
                                                                class="inline-flex items-center rounded-full bg-danger-50 px-3 py-1.5 text-xs font-medium text-danger-700 transition hover:bg-danger-100 dark:bg-danger-950/40 dark:text-danger-300"
                                                            >
                                                                Reject
                                                            </button>
                                                            @if($reviewStatus !== 'pending')
                                                                <button
                                                                    type="button"
                                                                    wire:click="markReviewDecision({{ (int) $item->channel_id }}, 'pending')"
                                                                    class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200"
                                                                >
                                                                    Reset
                                                                </button>
                                                            @endif
                                                        </div>
                                                    @elseif(! $canReviewItem)
                                                        <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                                            This candidate cannot be reviewed from the stored candidate table.
                                                        </div>
                                                    @endif

                                                    @if(filled($item->reviewed_by_user_name))
                                                        <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                                            {{ $item->reviewed_by_user_name }} · {{ optional($item->reviewed_at)->toDateTimeString() }}
                                                        </div>
                                                    @endif
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="mt-4">
                        {{ $candidateRows->links() }}
                    </div>
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        The stored candidate table is the source of truth for review and apply-reviewed actions. The run summary above remains compact on purpose.
                    </p>
                    @if($applyOutcomeBreakdown !== [])
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach($applyOutcomeBreakdown as $outcome => $count)
                                <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ \Illuminate\Support\Str::headline($outcome) }} · {{ $count }}</span>
                            @endforeach
                        </div>
                    @endif
                @else
                    <div class="mt-5 rounded-2xl border border-dashed border-gray-200 px-4 py-10 text-center text-sm text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        No per-channel evidence has been recorded for this run yet.
                    </div>
                @endif
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
            <section class="overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="border-b border-gray-200 px-6 py-5 dark:border-gray-800">
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Activity stream</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">This is the actual job trail. Each line is recorded while the plugin is executing.</p>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-800/80">
                    @forelse($this->logs as $log)
                        <article class="grid gap-4 px-6 py-4 lg:grid-cols-[170px_110px_minmax(0,1fr)]">
                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                {{ optional($log->created_at)->toDateTimeString() }}
                            </div>
                            <div>
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $log->level === 'error' ? 'bg-danger-50 text-danger-700 ring-danger-200 dark:bg-danger-950/40 dark:text-danger-300 dark:ring-danger-800' : ($log->level === 'warning' ? 'bg-warning-50 text-warning-700 ring-warning-200 dark:bg-warning-950/40 dark:text-warning-300 dark:ring-warning-800' : 'bg-info-50 text-info-700 ring-info-200 dark:bg-info-950/40 dark:text-info-300 dark:ring-info-800') }}">
                                    {{ \Illuminate\Support\Str::headline($log->level) }}
                                </span>
                            </div>
                            <div class="space-y-2">
                                <div class="text-sm font-medium text-gray-950 dark:text-white">{{ $log->message }}</div>
                                @if(! empty($log->context))
                                    <dl class="grid gap-2 rounded-2xl bg-gray-50 p-3 text-xs text-gray-600 dark:bg-gray-950/60 dark:text-gray-300 sm:grid-cols-2">
                                        @foreach(collect($log->context)->take(8) as $key => $value)
                                            <div>
                                                <dt class="font-semibold text-gray-700 dark:text-gray-200">{{ $key }}</dt>
                                                <dd class="mt-1 break-words">{{ is_scalar($value) || $value === null ? json_encode($value) : '[…]' }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="px-6 py-14 text-center text-sm text-gray-500 dark:text-gray-400">
                            No activity has been recorded for this run yet.
                        </div>
                    @endforelse
                </div>
            </section>

            <div class="space-y-6">
                <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Request payload</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">The exact arguments that queued this run.</p>
                    <pre class="mt-4 overflow-x-auto rounded-2xl bg-gray-950 px-4 py-4 text-xs leading-6 text-gray-100">{{ $payload !== false ? $payload : '{}' }}</pre>
                </section>

                <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Result snapshot</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Structured output saved by the plugin when the run finished.</p>
                    <pre class="mt-4 overflow-x-auto rounded-2xl bg-gray-950 px-4 py-4 text-xs leading-6 text-gray-100">{{ $result !== false ? $result : '{}' }}</pre>
                </section>
            </div>
        </div>
    </div>
</x-filament-panels::page>
