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
use Illuminate\Support\Facades\Hash;
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
    expect(data_get($scanRun->result, 'data.channels'))->toHaveCount(1);
    expect(data_get($scanRun->result, 'data.channels.0.issue'))->toBe('unmapped');
    expect(data_get($scanRun->result, 'data.channels.0.suggested_epg_channel_id'))->toBe($epgChannel->id);
    expect(data_get($scanRun->result, 'data.channels.0.repairable'))->toBeTrue();
    expect(data_get($scanRun->result, 'data.totals.epg_channels_available'))->toBe(1);
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
