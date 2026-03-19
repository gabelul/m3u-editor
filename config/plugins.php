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

    'owned_storage_roots' => [
        'plugin-data',
        'plugin-reports',
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
];
