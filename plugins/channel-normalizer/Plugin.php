<?php

namespace AppLocalPlugins\ChannelNormalizer;

use App\Models\Channel;
use App\Plugins\Contracts\ChannelProcessorPluginInterface;
use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;

/**
 * Channel Normalizer plugin for m3u-editor.
 *
 * Parses raw IPTV channel titles to extract clean names, resolves them
 * against a YAML alias map of canonical channel identifiers, and updates
 * stream_id for reliable EPG matching and cross-provider deduplication.
 *
 * Runs automatically after playlist sync (playlist.synced hook) or
 * manually via the "Normalize Playlist" action in the UI.
 *
 * The alias map lives in /opt/normalizer/aliases/ by default (volume-mounted
 * from the normalizer container), but the path is configurable via settings.
 */
class Plugin implements PluginInterface, ChannelProcessorPluginInterface, HookablePluginInterface
{
    /** Cached alias map instance — loaded once per execution. */
    private ?AliasMap $aliasMap = null;

    /**
     * Route manual actions to their handlers.
     *
     * @param string $action   - Action ID from plugin.json
     * @param array  $payload  - User-submitted form data
     * @param PluginExecutionContext $context - Execution context with settings, logging, progress
     * @return PluginActionResult
     */
    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return match ($action) {
            'health_check' => $this->healthCheck($context),
            'normalize_playlist' => $this->normalizeFromAction($payload, $context),
            default => PluginActionResult::failure("Unknown action [{$action}]."),
        };
    }

    /**
     * Handle system hooks — we only care about playlist.synced.
     *
     * When a playlist finishes syncing, we check if it's one of the
     * playlists configured in settings. If so, run normalization on
     * all its channels.
     *
     * @param string $hook    - Hook name (e.g. "playlist.synced")
     * @param array  $payload - Hook payload with playlist_id and user_id
     * @param PluginExecutionContext $context - Execution context
     * @return PluginActionResult
     */
    public function runHook(string $hook, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        if ($hook !== 'playlist.synced') {
            return PluginActionResult::success("Hook [{$hook}] not handled by Channel Normalizer.");
        }

        $playlistId = (int) ($payload['playlist_id'] ?? 0);

        if ($playlistId === 0) {
            return PluginActionResult::failure('Missing playlist_id in hook payload.');
        }

        // Only auto-normalize playlists the user has opted into
        $configured = $context->settings['default_playlist_id'] ?? null;
        $watchedIds = array_map('intval', array_filter((array) $configured));

        if ($watchedIds === []) {
            return PluginActionResult::success('No playlists configured for auto-normalization — skipping.');
        }

        if (! in_array($playlistId, $watchedIds, true)) {
            return PluginActionResult::success("Playlist #{$playlistId} not in configured defaults — skipping.");
        }

        return $this->normalizePlaylist($playlistId, $context);
    }

    /**
     * Health check: verify the alias map can be loaded and report stats.
     *
     * Shows how many canonical channels, ID mappings, and title mappings
     * are available — a quick sanity check that the YAML files are mounted
     * and parseable.
     */
    private function healthCheck(PluginExecutionContext $context): PluginActionResult
    {
        $aliasPath = $this->resolveAliasPath($context->settings);
        $context->info("Checking alias map at: {$aliasPath}");

        $map = AliasMap::fromDirectory($aliasPath);
        $stats = $map->stats();

        if ($stats['entries'] === 0) {
            return PluginActionResult::failure(
                "No alias entries found at {$aliasPath}. Check the alias_path setting and make sure YAML files exist.",
                $stats
            );
        }

        return PluginActionResult::success(
            sprintf(
                'Alias map loaded: %d channels, %d ID mappings, %d title mappings.',
                $stats['entries'],
                $stats['id_mappings'],
                $stats['title_mappings']
            ),
            array_merge($stats, ['alias_path' => $aliasPath])
        );
    }

    /**
     * Handle the normalize_playlist action — triggered from the UI.
     *
     * Extracts playlist_id from the action payload and delegates to
     * the shared normalizePlaylist() method.
     */
    private function normalizeFromAction(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $playlistId = (int) ($payload['playlist_id'] ?? 0);

        if ($playlistId === 0) {
            return PluginActionResult::failure('Pick a playlist to normalize.');
        }

        return $this->normalizePlaylist($playlistId, $context);
    }

    /**
     * The main normalization loop — where the actual work happens.
     *
     * For every enabled channel in the playlist:
     *   1. Clean the title using TitleCleaner (strips prefixes, suffixes, noise)
     *   2. Resolve against the alias map (stream_id match → title match)
     *   3. If matched and stream_id differs, update it to the canonical ID
     *   4. Optionally set title_custom to a clean display name
     *
     * Channels already carrying a canonical stream_id are skipped unless
     * overwrite_existing is enabled.
     *
     * @param int $playlistId - The playlist to process
     * @param PluginExecutionContext $context - Execution context
     * @return PluginActionResult
     */
    private function normalizePlaylist(int $playlistId, PluginExecutionContext $context): PluginActionResult
    {
        $settings = $context->settings;
        $isDryRun = $context->dryRun;
        $overwrite = (bool) ($settings['overwrite_existing'] ?? false);
        $skipVod = (bool) ($settings['skip_vod'] ?? true);
        $cleanTitles = (bool) ($settings['clean_titles'] ?? false);

        // Load the alias map (cached for this execution)
        $map = $this->getAliasMap($settings);
        $stats = $map->stats();

        if ($stats['entries'] === 0) {
            $aliasPath = $this->resolveAliasPath($settings);

            return PluginActionResult::failure(
                "Alias map at {$aliasPath} is empty — nothing to match against."
            );
        }

        $context->info(sprintf(
            'Alias map ready: %d channels, %d ID mappings. Processing playlist #%d%s.',
            $stats['entries'],
            $stats['id_mappings'],
            $playlistId,
            $isDryRun ? ' [dry run]' : ''
        ));

        // Build the channel query
        $query = Channel::query()
            ->where('playlist_id', $playlistId)
            ->where('enabled', true)
            ->select(['id', 'title', 'title_custom', 'name', 'name_custom', 'stream_id']);

        if ($skipVod) {
            $query->where('is_vod', false);
        }

        $channels = $query->get();
        $total = $channels->count();

        if ($total === 0) {
            return PluginActionResult::success('No enabled channels in this playlist.', [
                'total' => 0, 'matched' => 0, 'updated' => 0, 'skipped' => 0, 'unmatched' => 0,
            ]);
        }

        $matched = 0;
        $updated = 0;
        $skipped = 0;
        $unmatched = 0;

        foreach ($channels as $i => $channel) {
            // Check for user cancellation every 100 channels
            if (($i + 1) % 100 === 0 && $context->cancellationRequested()) {
                $context->warning("Cancelled at channel {$i}/{$total}.");

                return PluginActionResult::cancelled(
                    sprintf('Stopped after %d of %d channels. %d matched, %d updated.', $i, $total, $matched, $updated),
                    compact('matched', 'updated', 'skipped', 'unmatched', 'total')
                );
            }

            $rawTitle = trim((string) ($channel->title ?? ''));
            $currentStreamId = trim((string) ($channel->stream_id ?? ''));

            // Skip channels with no title at all
            if ($rawTitle === '') {
                $unmatched++;
                continue;
            }

            // If stream_id is already canonical and we're not overwriting, skip
            if (! $overwrite && $currentStreamId !== '' && $map->isCanonical($currentStreamId)) {
                $skipped++;
                continue;
            }

            // Clean the title and try to resolve a canonical ID
            $cleanedTitle = TitleCleaner::clean($rawTitle);
            $canonicalId = $map->resolve($currentStreamId, $cleanedTitle);

            if ($canonicalId === null) {
                $unmatched++;
                continue;
            }

            $matched++;

            // Nothing to change if stream_id already matches
            if ($currentStreamId === $canonicalId && ! $cleanTitles) {
                $skipped++;
                continue;
            }

            // Build the update payload
            $updates = [];

            if ($currentStreamId !== $canonicalId) {
                $updates['stream_id'] = $canonicalId;
            }

            // Optionally set a clean display title (only if user hasn't customized it)
            if ($cleanTitles && empty($channel->title_custom)) {
                $displayTitle = $map->getDisplayTitle($canonicalId);
                if ($displayTitle !== '' && $displayTitle !== $rawTitle) {
                    $updates['title_custom'] = $displayTitle;
                }
            }

            if ($updates !== [] && ! $isDryRun) {
                Channel::where('id', $channel->id)->update($updates);
            }

            if ($updates !== []) {
                $updated++;
                $context->info(sprintf(
                    '"%s" → %s%s',
                    $rawTitle,
                    $canonicalId,
                    $isDryRun ? ' (dry run)' : ''
                ));
            }

            // Report progress every 50 channels
            if (($i + 1) % 50 === 0) {
                $context->heartbeat(
                    message: sprintf('%d/%d processed, %d matched', $i + 1, $total, $matched),
                    progress: (int) ((($i + 1) / $total) * 100)
                );
            }
        }

        $summary = sprintf(
            '%d of %d channels matched, %d updated, %d skipped, %d unmatched%s.',
            $matched,
            $total,
            $updated,
            $skipped,
            $unmatched,
            $isDryRun ? ' (dry run — no changes written)' : ''
        );

        return PluginActionResult::success($summary, [
            'matched' => $matched,
            'updated' => $updated,
            'skipped' => $skipped,
            'unmatched' => $unmatched,
            'total' => $total,
            'dry_run' => $isDryRun,
        ]);
    }

    /**
     * Get or create the alias map for this execution.
     *
     * Caches the loaded map so we don't re-parse YAML files
     * if the plugin processes multiple playlists in one run.
     *
     * @param array $settings - Plugin settings with alias_path
     * @return AliasMap
     */
    private function getAliasMap(array $settings): AliasMap
    {
        if ($this->aliasMap === null) {
            $this->aliasMap = AliasMap::fromDirectory($this->resolveAliasPath($settings));
        }

        return $this->aliasMap;
    }

    /**
     * Figure out where the alias YAML files live.
     *
     * Defaults to /opt/normalizer/aliases (the Docker volume mount),
     * but users can override via the alias_path setting.
     *
     * @param array $settings - Plugin settings
     * @return string Absolute path to the aliases directory
     */
    private function resolveAliasPath(array $settings): string
    {
        $path = trim((string) ($settings['alias_path'] ?? ''));

        return $path !== '' ? $path : '/opt/normalizer/aliases';
    }
}
