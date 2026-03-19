<?php

use App\Enums\Status;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\ExtensionPlugin;
use App\Models\ExtensionPluginRun;
use App\Models\ExtensionPluginRunLog;
use App\Models\Playlist;
use App\Models\User;
use App\Filament\Resources\ExtensionPlugins\Pages\ViewPluginRun;
use App\Plugins\PluginManager;
use App\Plugins\PluginSchemaMapper;
use App\Jobs\ExecutePluginInvocation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

it('discovers the bundled epg repair plugin as a valid local plugin', function () {
    $plugins = app(PluginManager::class)->discover();

    expect($plugins)->toHaveCount(1);

    $plugin = ExtensionPlugin::query()->where('plugin_id', 'epg-repair')->first();

    expect($plugin)->not->toBeNull();
    expect($plugin->validation_status)->toBe('valid');
    expect($plugin->available)->toBeTrue();
    expect($plugin->class_name)->toBe('AppLocalPlugins\\EpgRepair\\Plugin');
    expect($plugin->capabilities)->toContain('epg_repair');
    expect($plugin->actions)->toBeArray();
});

it('validates a discovered plugin from the registry', function () {
    $plugin = app(PluginManager::class)->discover()[0];

    $validated = app(PluginManager::class)->validate($plugin);

    expect($validated->validation_status)->toBe('valid');
    expect($validated->validation_errors)->toBe([]);
});

it('scans and applies epg repairs through the plugin manager', function () {
    $pluginManager = app(PluginManager::class);
    $plugin = $pluginManager->discover()[0];
    $plugin->update(['enabled' => true]);

    $user = User::create([
        'name' => 'Plugin Tester',
        'email' => 'plugin-test-'.Str::random(10).'@example.com',
        'password' => Hash::make('password'),
        'email_verified_at' => now(),
    ]);

    $playlist = Playlist::create([
        'name' => 'Plugin Test Playlist',
        'uuid' => (string) Str::uuid(),
        'url' => 'http://example.test/playlist.m3u',
        'status' => Status::Completed,
        'prefix' => 'test',
        'channels' => 1,
        'synced' => now(),
        'id_channel_by' => 'stream_id',
        'user_id' => $user->id,
    ]);

    $epg = Epg::create([
        'name' => 'Plugin Test EPG',
        'url' => 'http://example.test/epg.xml',
        'user_id' => $user->id,
        'status' => Status::Completed,
    ]);

    $epgChannel = EpgChannel::create([
        'name' => 'BBC One HD',
        'display_name' => 'BBC One HD',
        'lang' => 'en',
        'channel_id' => 'bbc-one-hd',
        'epg_id' => $epg->id,
        'user_id' => $user->id,
    ]);

    $channel = Channel::create([
        'name' => 'BBC One HD',
        'title' => 'BBC One HD',
        'enabled' => true,
        'channel' => 1,
        'shift' => 0,
        'url' => 'http://stream.example.test/live.ts',
        'logo' => '',
        'group' => 'Test',
        'stream_id' => '1',
        'lang' => 'en',
        'country' => 'GB',
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'group_id' => null,
        'is_vod' => false,
        'epg_channel_id' => null,
    ]);

    $scanRun = $pluginManager->executeAction($plugin->fresh(), 'scan', [
        'playlist_id' => $playlist->id,
        'epg_id' => $epg->id,
        'hours_ahead' => 12,
        'confidence_threshold' => 0.6,
    ], [
        'trigger' => 'manual',
        'dry_run' => true,
        'user_id' => $user->id,
    ]);

    expect($scanRun->status)->toBe('completed');
    expect(data_get($scanRun->result, 'data.channels_preview'))->toHaveCount(1);
    expect(data_get($scanRun->result, 'data.channels_preview.0.issue'))->toBe('unmapped');
    expect(data_get($scanRun->result, 'data.channels_preview.0.suggested_epg_channel_id'))->toBe($epgChannel->id);
    expect(data_get($scanRun->result, 'data.channels_preview.0.repairable'))->toBeTrue();
    expect(data_get($scanRun->result, 'data.channels_total_count'))->toBe(1);
    expect(data_get($scanRun->result, 'data.channels_truncated'))->toBeFalse();
    expect(data_get($scanRun->result, 'data.totals.epg_channels_available'))->toBe(1);
    expect($scanRun->progress)->toBe(100);
    expect(data_get($scanRun->result, 'status'))->toBe('completed');
    expect($scanRun->last_heartbeat_at)->not->toBeNull();
    expect($scanRun->run_state)->toBeNull();
    expect($scanRun->logs()->count())->toBeGreaterThanOrEqual(2);
    expect($scanRun->logs()->pluck('message')->join(' '))->toContain('Starting EPG Repair scan.');

    $applyRun = $pluginManager->executeAction($plugin->fresh(), 'apply', [
        'playlist_id' => $playlist->id,
        'epg_id' => $epg->id,
        'hours_ahead' => 12,
        'confidence_threshold' => 0.6,
    ], [
        'trigger' => 'manual',
        'dry_run' => false,
        'user_id' => $user->id,
    ]);

    expect($applyRun->status)->toBe('completed');
    expect($applyRun->progress)->toBe(100);
    expect($applyRun->last_heartbeat_at)->not->toBeNull();
    expect($applyRun->run_state)->toBeNull();

    $channel->refresh();

    expect($channel->epg_channel_id)->toBe($epgChannel->id);
    expect(data_get($applyRun->result, 'data.totals.repairs_applied'))->toBe(1);
    expect($applyRun->logs()->pluck('message')->join(' '))->toContain('Applied EPG repair to channel.');
    expect(ExtensionPluginRunLog::query()->count())->toBeGreaterThanOrEqual(4);
});

it('explains when a scan has no enabled live channels to inspect', function () {
    $pluginManager = app(PluginManager::class);
    $plugin = $pluginManager->discover()[0];
    $plugin->update(['enabled' => true]);

    $user = User::create([
        'name' => 'Empty Playlist Tester',
        'email' => 'empty-playlist-'.Str::random(10).'@example.com',
        'password' => Hash::make('password'),
        'email_verified_at' => now(),
    ]);

    $playlist = Playlist::create([
        'name' => 'Empty Playlist',
        'uuid' => (string) Str::uuid(),
        'url' => 'http://example.test/empty.m3u',
        'status' => Status::Completed,
        'prefix' => 'empty',
        'channels' => 0,
        'synced' => now(),
        'id_channel_by' => 'stream_id',
        'user_id' => $user->id,
    ]);

    $epg = Epg::create([
        'name' => 'Empty Playlist EPG',
        'url' => 'http://example.test/epg.xml',
        'user_id' => $user->id,
        'status' => Status::Completed,
    ]);

    $scanRun = $pluginManager->executeAction($plugin->fresh(), 'scan', [
        'playlist_id' => $playlist->id,
        'epg_id' => $epg->id,
        'hours_ahead' => 12,
        'confidence_threshold' => 0.6,
    ], [
        'trigger' => 'manual',
        'dry_run' => true,
        'user_id' => $user->id,
    ]);

    expect($scanRun->status)->toBe('completed');
    expect($scanRun->summary)->toContain('no enabled live channels');
    expect(data_get($scanRun->result, 'data.totals.channels_scanned'))->toBe(0);
    expect($scanRun->logs()->pluck('message')->join(' '))->toContain('no enabled live channels');
});

it('prefills plugin action fields from saved settings when declared', function () {
    $plugin = app(PluginManager::class)->discover()[0];

    $plugin->forceFill([
        'settings' => [
            'default_playlist_id' => 42,
            'default_epg_id' => 77,
            'hours_ahead' => 24,
            'confidence_threshold' => 0.8,
        ],
    ]);

    $components = collect(app(PluginSchemaMapper::class)->actionComponents($plugin, 'scan'))
        ->keyBy(fn ($component) => $component->getName());

    expect($components['playlist_id']->getDefaultState())->toBe(42);
    expect($components['epg_id']->getDefaultState())->toBe(77);
    expect($components['hours_ahead']->getDefaultState())->toBe(24);
    expect($components['confidence_threshold']->getDefaultState())->toBe(0.8);
});

it('loads a plugin run detail page inside the plugin resource', function () {
    $plugin = app(PluginManager::class)->discover()[0];
    $plugin->update(['enabled' => true]);

    $user = User::factory()->create([
        'permissions' => ['use_tools'],
    ]);

    $this->actingAs($user);

    $run = ExtensionPluginRun::query()->create([
        'extension_plugin_id' => $plugin->id,
        'user_id' => $user->id,
        'status' => 'running',
        'invocation_type' => 'action',
        'action' => 'scan',
        'trigger' => 'manual',
        'dry_run' => true,
        'payload' => ['playlist_id' => 123],
        'summary' => 'Queued for inspection.',
        'started_at' => now(),
    ]);

    $run->logs()->create([
        'level' => 'info',
        'message' => 'Plugin run started.',
        'context' => ['playlist_id' => 123],
    ]);

    Livewire::test(ViewPluginRun::class, [
        'record' => $plugin->id,
        'run' => $run->id,
    ])
        ->assertOk()
        ->assertSee('Plugin run started.')
        ->assertSee('Queued for inspection.');
});

it('marks stale runs, supports cancellation requests, and queues resume for stale runs', function () {
    $pluginManager = app(PluginManager::class);
    $plugin = $pluginManager->discover()[0];
    $plugin->update(['enabled' => true]);

    $user = User::factory()->create([
        'permissions' => ['use_tools'],
    ]);

    $staleRun = ExtensionPluginRun::query()->create([
        'extension_plugin_id' => $plugin->id,
        'user_id' => $user->id,
        'status' => 'running',
        'invocation_type' => 'action',
        'action' => 'scan',
        'trigger' => 'manual',
        'dry_run' => true,
        'payload' => ['playlist_id' => 123],
        'progress' => 42,
        'progress_message' => 'Still working through checkpoint 3.',
        'last_heartbeat_at' => now()->subMinutes(20),
        'started_at' => now()->subMinutes(25),
        'run_state' => [
            'epg_repair' => [
                'last_channel_id' => 999,
                'channels_scanned' => 420,
            ],
        ],
    ]);

    expect($pluginManager->recoverStaleRuns())->toBe(1);

    $staleRun->refresh();

    expect($staleRun->status)->toBe('stale');
    expect($staleRun->stale_at)->not->toBeNull();
    expect(data_get($staleRun->result, 'status'))->toBe('stale');

    $runningRun = ExtensionPluginRun::query()->create([
        'extension_plugin_id' => $plugin->id,
        'user_id' => $user->id,
        'status' => 'running',
        'invocation_type' => 'action',
        'action' => 'scan',
        'trigger' => 'manual',
        'dry_run' => true,
        'payload' => ['playlist_id' => 456],
        'started_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    $pluginManager->requestCancellation($runningRun, $user->id);
    $runningRun->refresh();

    expect($runningRun->cancel_requested)->toBeTrue();
    expect($runningRun->cancel_requested_at)->not->toBeNull();
    expect($runningRun->progress_message)->toContain('Cancellation requested');

    Queue::fake();

    $pluginManager->resumeRun($staleRun, $user->id);

    Queue::assertPushed(ExecutePluginInvocation::class, function (ExecutePluginInvocation $job) use ($plugin, $staleRun, $user) {
        return $job->pluginId === $plugin->id
            && $job->invocationType === 'action'
            && $job->name === 'scan'
            && $job->options['existing_run_id'] === $staleRun->id
            && $job->options['user_id'] === $user->id;
    });
});
