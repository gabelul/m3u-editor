<?php

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\M3uProxyService;
use Mockery;

it('includes channel identity in active proxy stream model payload', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->create([
        'user_id' => $user->id,
        'uuid' => 'playlist-test-uuid',
    ]);
    $channel = Channel::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'channel' => 101,
        'name' => 'Test Channel',
    ]);

    $proxyService = Mockery::mock(M3uProxyService::class);
    $proxyService->shouldReceive('fetchActiveStreams')->once()->andReturn([
        'success' => true,
        'streams' => [[
            'stream_id' => 'stream-1',
            'original_url' => 'http://example.test/live.ts',
            'current_url' => 'http://proxy.test/live.ts',
            'stream_type' => 'ts',
            'is_active' => true,
            'client_count' => 1,
            'total_bytes_served' => 1024,
            'created_at' => '2026-02-26 00:00:00',
            'has_failover' => false,
            'error_count' => 0,
            'total_segments_served' => 0,
            'metadata' => [
                'type' => 'channel',
                'id' => $channel->id,
                'playlist_uuid' => 'playlist-test-uuid',
            ],
        ]],
    ]);
    $proxyService->shouldReceive('fetchActiveClients')->once()->andReturn([
        'success' => true,
        'clients' => [],
    ]);

    app()->instance(M3uProxyService::class, $proxyService);

    $this->actingAs($user);

    $response = $this->getJson(route('api.proxy.streams'));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('streams.0.model.type', 'channel')
        ->assertJsonPath('streams.0.model.id', $channel->id)
        ->assertJsonPath('streams.0.model.channel_number', 101)
        ->assertJsonPath('streams.0.model.playlist_uuid', 'playlist-test-uuid');
});
