<?php

return [
    'api_version' => '1.0.0',

    'directories' => [
        base_path('plugins'),
    ],

    'cleanup_modes' => [
        'preserve',
        'purge',
    ],

    'trust_states' => [
        'pending_review',
        'trusted',
        'blocked',
    ],

    'integrity_statuses' => [
        'unknown',
        'verified',
        'changed',
        'missing',
    ],

    'owned_storage_roots' => [
        'plugin-data',
        'plugin-reports',
    ],

    'permissions' => [
        'db_read' => 'Read plugin-relevant database records',
        'db_write' => 'Write plugin-relevant database records',
        'schema_manage' => 'Ask the host to create or remove plugin-owned schema',
        'filesystem_read' => 'Read plugin-owned files from storage',
        'filesystem_write' => 'Write plugin-owned files to storage',
        'network_egress' => 'Call external services or remote APIs',
        'queue_jobs' => 'Run actions, hooks, or schedules through background jobs',
        'hook_subscriptions' => 'Receive host lifecycle hook invocations',
        'scheduled_runs' => 'Run scheduled plugin actions',
    ],

    'capabilities' => [
        'epg_repair' => \App\Plugins\Contracts\EpgRepairPluginInterface::class,
        'epg_processor' => \App\Plugins\Contracts\EpgProcessorPluginInterface::class,
        'channel_processor' => \App\Plugins\Contracts\ChannelProcessorPluginInterface::class,
        'matcher_provider' => \App\Plugins\Contracts\MatcherProviderInterface::class,
        'stream_analysis' => \App\Plugins\Contracts\StreamAnalysisPluginInterface::class,
        'scheduled' => \App\Plugins\Contracts\ScheduledPluginInterface::class,
    ],

    'hooks' => [
        'playlist.synced',
        'epg.synced',
        'epg.cache.generated',
        'before.epg.map',
        'after.epg.map',
        'before.epg.output.generate',
        'after.epg.output.generate',
    ],

    'field_types' => [
        'boolean',
        'number',
        'text',
        'textarea',
        'select',
        'model_select',
    ],

    'schema_column_types' => [
        'id',
        'foreignId',
        'string',
        'text',
        'boolean',
        'integer',
        'bigInteger',
        'decimal',
        'json',
        'timestamp',
        'timestamps',
    ],

    'schema_index_types' => [
        'index',
        'unique',
    ],
];
