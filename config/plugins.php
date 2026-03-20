<?php

return [
    'api_version' => '1.0.0',

    'install_mode' => env('PLUGIN_INSTALL_MODE', 'normal'),

    'directories' => [
        base_path('plugins'),
    ],

    'dev_directories' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PLUGIN_DEV_DIRECTORIES', ''))
    ))),

    'staging_directory' => storage_path('app/plugin-staging'),

    'review_statuses' => [
        'staged',
        'scanned',
        'review_ready',
        'approved',
        'rejected',
        'installed',
        'discarded',
    ],

    'scan_statuses' => [
        'pending',
        'clean',
        'infected',
        'scan_failed',
        'scanner_unavailable',
    ],

    'source_types' => [
        'local_directory',
        'staged_archive',
        'local_dev',
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

    'clamav' => [
        'driver' => env('PLUGIN_SCAN_DRIVER', 'clamav'),
        'binary' => env('CLAMAV_BINARY', 'clamscan'),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 60),
        'required_for_trust' => (bool) env('PLUGIN_SCAN_REQUIRED_FOR_TRUST', true),
        'fake_result' => env('PLUGIN_SCAN_FAKE_RESULT', 'clean'),
        'scan_archive_files' => (bool) env('PLUGIN_SCAN_ARCHIVES', true),
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
