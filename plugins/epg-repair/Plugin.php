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
    private const SCAN_CHUNK_SIZE = 250;

    private const CHECKPOINT_EVERY_CHUNKS = 5;

    private const MAX_DETAILED_APPLY_LOGS = 25;

    private const MAX_RESULT_CHANNELS = 50;

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

        [$issues, $cancelled] = $this->processRepairStream(
            playlist: $playlist,
            epg: $epg,
            hoursAhead: $hoursAhead,
            threshold: $threshold,
            context: $context,
            applyRepairs: false,
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
                    ...$this->resultSnapshot($issues),
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
                ...$this->resultSnapshot($issues),
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

        [$report, $cancelled] = $this->processRepairStream(
            playlist: $playlist,
            epg: $epg,
            hoursAhead: $hoursAhead,
            threshold: $threshold,
            context: $context,
            applyRepairs: true,
        );

        $applied = $report['totals']['repairs_applied'] ?? 0;

        if ($cancelled) {
            return PluginActionResult::cancelled(
                "Apply stopped after {$applied} EPG repair(s). Resume the run to continue from the last saved checkpoint.",
                [
                    'dry_run' => false,
                    ...$this->resultSnapshot($report),
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
                ...$this->resultSnapshot($report),
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
    ): array
    {
        $mode = $applyRepairs ? 'apply' : 'scan';
        $totalChannels = (int) $playlist->enabled_live_channels()->count();
        $epgChannelsAvailable = (int) $epg->channels()->count();
        $checkpoint = $this->initialCheckpointState(
            playlist: $playlist,
            epg: $epg,
            hoursAhead: $hoursAhead,
            threshold: $threshold,
            totalChannels: $totalChannels,
            epgChannelsAvailable: $epgChannelsAvailable,
            context: $context,
            mode: $mode,
        );

        $start = Carbon::now();
        $end = $start->copy()->addHours($hoursAhead);
        $chunkNumber = 0;
        $detailedApplyLogs = (int) ($checkpoint['detailed_apply_logs'] ?? 0);
        $cancelled = false;

        $query = $playlist->enabled_live_channels()
            ->with(['epgChannel'])
            ->orderBy('channels.id');

        if (($checkpoint['last_channel_id'] ?? null) !== null) {
            $query->where('channels.id', '>', $checkpoint['last_channel_id']);
        }

        $query->chunkById(self::SCAN_CHUNK_SIZE, function (Collection $channels) use (
            $applyRepairs,
            $context,
            $epg,
            $end,
            &$cancelled,
            &$checkpoint,
            &$chunkNumber,
            &$detailedApplyLogs,
            $start,
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

                $suggested = $this->similaritySearch->findMatchingEpgChannel($channel, $epg);
                $confidence = $suggested ? $this->confidenceScore($channel, $suggested) : null;
                $repairable = $suggested !== null && $confidence !== null && $confidence >= $threshold;

                if ($repairable) {
                    $checkpoint['repair_candidates']++;
                }

                $item = [
                    'channel_id' => $channel->id,
                    'channel_name' => $channel->title_custom ?? $channel->title ?? $channel->name_custom ?? $channel->name,
                    'issue' => $issue,
                    'current_epg_channel_id' => $channel->epg_channel_id,
                    'suggested_epg_channel_id' => $suggested?->id,
                    'suggested_epg_channel_name' => $suggested?->display_name ?? $suggested?->name ?? $suggested?->channel_id,
                    'confidence' => $confidence,
                    'repairable' => $repairable,
                ];

                $checkpoint['channels'][] = $item;

                if (! $applyRepairs || ! $repairable) {
                    continue;
                }

                Channel::query()
                    ->whereKey($channel->id)
                    ->update([
                        'epg_channel_id' => $item['suggested_epg_channel_id'],
                    ]);

                $checkpoint['repairs_applied']++;

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
            'channels' => $checkpoint['channels'],
            'totals' => [
                'channels_scanned' => $checkpoint['channels_scanned'],
                'issues_found' => $checkpoint['issues_found'],
                'repair_candidates' => $checkpoint['repair_candidates'],
                'repairs_applied' => $checkpoint['repairs_applied'],
                'epg_channels_available' => $checkpoint['epg_channels_available'],
                'channels_with_existing_programmes' => $checkpoint['channels_with_existing_programmes'],
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

    private function initialCheckpointState(
        Playlist $playlist,
        Epg $epg,
        int $hoursAhead,
        float $threshold,
        int $totalChannels,
        int $epgChannelsAvailable,
        PluginExecutionContext $context,
        string $mode,
    ): array {
        $state = $context->state('epg_repair', []);

        $canResume = ($state['mode'] ?? null) === $mode
            && ($state['playlist_id'] ?? null) === $playlist->id
            && ($state['epg_id'] ?? null) === $epg->id
            && (int) ($state['hours_ahead'] ?? 0) === $hoursAhead
            && (float) ($state['confidence_threshold'] ?? 0) === $threshold;

        if (! $canResume) {
            return [
                'mode' => $mode,
                'playlist_id' => $playlist->id,
                'epg_id' => $epg->id,
                'hours_ahead' => $hoursAhead,
                'confidence_threshold' => $threshold,
                'total_channels' => $totalChannels,
                'epg_channels_available' => $epgChannelsAvailable,
                'last_channel_id' => null,
                'channels_scanned' => 0,
                'issues_found' => 0,
                'repair_candidates' => 0,
                'repairs_applied' => 0,
                'channels_with_existing_programmes' => 0,
                'detailed_apply_logs' => 0,
                'channels' => [],
            ];
        }

        $state['total_channels'] = $totalChannels;
        $state['epg_channels_available'] = $epgChannelsAvailable;
        $state['channels'] = $state['channels'] ?? [];
        $state['repairs_applied'] = (int) ($state['repairs_applied'] ?? 0);
        $state['detailed_apply_logs'] = (int) ($state['detailed_apply_logs'] ?? 0);

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

    private function resultSnapshot(array $report): array
    {
        $channels = $report['channels'] ?? [];
        $preview = array_slice($channels, 0, self::MAX_RESULT_CHANNELS);

        return [
            'progress' => $report['progress'] ?? 100,
            'playlist' => $report['playlist'] ?? null,
            'epg' => $report['epg'] ?? null,
            'totals' => $report['totals'] ?? [],
            'channels_preview' => $preview,
            'channels_preview_count' => count($preview),
            'channels_total_count' => count($channels),
            'channels_truncated' => count($channels) > count($preview),
        ];
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
