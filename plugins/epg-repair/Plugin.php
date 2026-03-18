<?php

namespace AppLocalPlugins\EpgRepair;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
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

class Plugin implements EpgRepairPluginInterface, HookablePluginInterface, ScheduledPluginInterface
{
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

        $context->info('Starting EPG Repair scan.', [
            'playlist_id' => $playlist->id,
            'playlist_name' => $playlist->name,
            'epg_id' => $epg->id,
            'epg_name' => $epg->name,
            'hours_ahead' => $hoursAhead,
            'confidence_threshold' => $threshold,
            'dry_run' => $context->dryRun || $implicitDryRun,
        ]);

        $issues = $this->buildRepairReport($playlist, $epg, $hoursAhead, $threshold);

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
                ...$issues,
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

        $context->info('Starting EPG Repair apply run.', [
            'playlist_id' => $playlist->id,
            'playlist_name' => $playlist->name,
            'epg_id' => $epg->id,
            'epg_name' => $epg->name,
            'hours_ahead' => $hoursAhead,
            'confidence_threshold' => $threshold,
        ]);

        $report = $this->buildRepairReport($playlist, $epg, $hoursAhead, $threshold);
        $applied = 0;

        foreach ($report['channels'] as $item) {
            if (($item['repairable'] ?? false) !== true) {
                continue;
            }

            Channel::query()
                ->whereKey($item['channel_id'])
                ->update([
                    'epg_channel_id' => $item['suggested_epg_channel_id'],
                ]);

            $context->info('Applied EPG repair to channel.', [
                'channel_id' => $item['channel_id'],
                'channel_name' => $item['channel_name'],
                'suggested_epg_channel_id' => $item['suggested_epg_channel_id'],
                'suggested_epg_channel_name' => $item['suggested_epg_channel_name'],
                'confidence' => $item['confidence'],
            ]);
            $applied++;
        }

        if ($applied === 0) {
            $context->warning('Apply finished with no repairs applied.', [
                'issues_found' => $report['totals']['issues_found'],
                'repair_candidates' => $report['totals']['repair_candidates'],
            ]);
        }

        return PluginActionResult::success(
            "Applied {$applied} EPG repair(s).",
            [
                ...$report,
                'dry_run' => false,
                'totals' => [
                    ...$report['totals'],
                    'repairs_applied' => $applied,
                ],
            ],
        );
    }

    private function buildRepairReport(Playlist $playlist, Epg $epg, int $hoursAhead, float $threshold): array
    {
        $channels = $playlist->enabled_live_channels()
            ->with(['epgChannel'])
            ->get();

        $start = Carbon::now();
        $end = $start->copy()->addHours($hoursAhead);

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

        /** @var Collection<int, EpgChannel> $epgChannels */
        $epgChannels = $epg->channels()->get();

        $results = $channels->map(function (Channel $channel) use ($epg, $epgChannels, $programmes, $threshold) {
            $issue = $this->detectIssue($channel, $epg, $programmes);
            if (! $issue) {
                return null;
            }

            $suggested = $this->similaritySearch->findMatchingEpgChannel($channel, $epg);
            $confidence = $suggested ? $this->confidenceScore($channel, $suggested) : null;
            $repairable = $suggested !== null && $confidence !== null && $confidence >= $threshold;

            return [
                'channel_id' => $channel->id,
                'channel_name' => $channel->title_custom ?? $channel->title ?? $channel->name_custom ?? $channel->name,
                'issue' => $issue,
                'current_epg_channel_id' => $channel->epg_channel_id,
                'suggested_epg_channel_id' => $suggested?->id,
                'suggested_epg_channel_name' => $suggested?->display_name ?? $suggested?->name ?? $suggested?->channel_id,
                'confidence' => $confidence,
                'repairable' => $repairable,
            ];
        })->filter()->values();

        return [
            'playlist' => [
                'id' => $playlist->id,
                'name' => $playlist->name,
            ],
            'epg' => [
                'id' => $epg->id,
                'name' => $epg->name,
            ],
            'channels' => $results->all(),
            'totals' => [
                'channels_scanned' => $channels->count(),
                'issues_found' => $results->count(),
                'repair_candidates' => $results->where('repairable', true)->count(),
                'epg_channels_available' => $epgChannels->count(),
                'channels_with_existing_programmes' => count($mappedChannelIds),
            ],
        ];
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
        if (! $channel->epg_channel_id) {
            return 'unmapped';
        }

        if (! $channel->epgChannel || $channel->epgChannel->epg_id !== $epg->id) {
            return 'mapped_to_other_epg';
        }

        $channelKey = $channel->epgChannel->channel_id;
        if ($channelKey && empty($programmes[$channelKey] ?? [])) {
            return 'mapped_but_empty';
        }

        return null;
    }

    private function confidenceScore(Channel $channel, EpgChannel $epgChannel): ?float
    {
        $channelName = $channel->title_custom ?? $channel->title ?? $channel->name_custom ?? $channel->name;
        $candidateNames = array_filter([
            $epgChannel->display_name,
            $epgChannel->name,
            $epgChannel->channel_id,
        ]);

        $normalizedChannel = $this->normalizer->normalize($channelName);
        if ($normalizedChannel === '') {
            return null;
        }

        $best = 0.0;
        foreach ($candidateNames as $candidateName) {
            $normalizedCandidate = $this->normalizer->normalize($candidateName);
            if ($normalizedCandidate === '') {
                continue;
            }

            $distance = levenshtein($normalizedChannel, $normalizedCandidate);
            $length = max(strlen($normalizedChannel), strlen($normalizedCandidate));
            $score = $length > 0 ? max(0, 1 - ($distance / $length)) : 0;
            $best = max($best, round($score, 4));
        }

        return $best > 0 ? $best : null;
    }
}
