<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakePlugin extends Command
{
    protected $signature = 'make:plugin
        {name : Human-friendly plugin name or slug}
        {--description= : Plugin description written into plugin.json}
        {--capability=* : Capability ids to declare}
        {--hook=* : Hook names to subscribe to}
        {--cleanup=preserve : Default cleanup mode for uninstall (preserve|purge)}
        {--lifecycle : Include a lifecycle uninstall hook stub}
        {--bare : Generate only plugin.json and Plugin.php without the starter kit}
        {--force : Overwrite the target plugin directory if it already exists}';

    protected $description = 'Scaffold a trusted-local plugin with a valid manifest and Plugin.php entrypoint.';

    public function handle(): int
    {
        $requestedName = trim((string) $this->argument('name'));
        $pluginId = Str::slug($requestedName);

        if ($pluginId === '') {
            $this->error('The plugin name could not be converted into a valid plugin id.');

            return self::FAILURE;
        }

        $displayName = Str::of($requestedName)
            ->replace(['-', '_'], ' ')
            ->squish()
            ->title()
            ->value();

        $classSegment = Str::studly(Str::of($requestedName)->replace(['-', '_'], ' ')->squish()->value());
        $pluginRoot = collect(config('plugins.directories', [base_path('plugins')]))->first() ?: base_path('plugins');
        $pluginPath = rtrim((string) $pluginRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$pluginId;

        $capabilities = $this->normalizeListOption($this->option('capability'));
        $unknownCapabilities = array_values(array_diff($capabilities, array_keys(config('plugins.capabilities', []))));
        if ($unknownCapabilities !== []) {
            $this->error('Unknown capability value(s): '.implode(', ', $unknownCapabilities));
            $this->line('Known capabilities: '.implode(', ', array_keys(config('plugins.capabilities', []))));

            return self::FAILURE;
        }

        $hooks = $this->normalizeListOption($this->option('hook'));
        $unknownHooks = array_values(array_diff($hooks, config('plugins.hooks', [])));
        if ($unknownHooks !== []) {
            $this->error('Unknown hook value(s): '.implode(', ', $unknownHooks));
            $this->line('Known hooks: '.implode(', ', config('plugins.hooks', [])));

            return self::FAILURE;
        }

        $cleanupMode = (string) $this->option('cleanup');
        if (! in_array($cleanupMode, config('plugins.cleanup_modes', []), true)) {
            $this->error("Unsupported cleanup mode [{$cleanupMode}].");
            $this->line('Supported cleanup modes: '.implode(', ', config('plugins.cleanup_modes', [])));

            return self::FAILURE;
        }

        if (File::exists($pluginPath)) {
            if (! $this->option('force')) {
                $this->error("Plugin directory [plugins/{$pluginId}] already exists. Re-run with --force to replace it.");

                return self::FAILURE;
            }

            File::deleteDirectory($pluginPath);
        }

        File::ensureDirectoryExists($pluginPath);

        $manifest = $this->buildManifest(
            pluginId: $pluginId,
            displayName: $displayName,
            classSegment: $classSegment,
            description: (string) ($this->option('description') ?: "Generated plugin scaffold for {$displayName}."),
            capabilities: $capabilities,
            hooks: $hooks,
            cleanupMode: $cleanupMode,
        );

        File::put(
            $pluginPath.'/plugin.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        File::put(
            $pluginPath.'/Plugin.php',
            $this->renderClassStub(
                classSegment: $classSegment,
                pluginId: $pluginId,
                displayName: $displayName,
                capabilities: $capabilities,
                hooks: $hooks,
                withLifecycleHook: (bool) $this->option('lifecycle'),
            ),
        );

        if (! $this->option('bare')) {
            $this->writeStarterKitFiles(
                pluginPath: $pluginPath,
                pluginId: $pluginId,
                displayName: $displayName,
                classSegment: $classSegment,
                capabilities: $capabilities,
                hooks: $hooks,
            );
        }

        $relativePath = Str::startsWith($pluginPath, base_path().DIRECTORY_SEPARATOR)
            ? Str::after($pluginPath, base_path().DIRECTORY_SEPARATOR)
            : $pluginPath;

        $this->info("Created plugin [{$pluginId}] in [{$relativePath}].");
        $this->line('Next steps:');
        $this->line("  php artisan plugins:stage-directory {$relativePath}");
        $this->line('  php artisan plugins:scan-install <review-id>');
        $this->line('  php artisan plugins:approve-install <review-id> --trust');
        $this->line('  php artisan plugins:discover');
        if (! $this->option('bare')) {
            $this->line("  bash {$relativePath}/scripts/package-plugin.sh");
            $this->line('  Publish the packaged zip with its SHA-256 checksum for reviewed GitHub installs.');
        }

        return self::SUCCESS;
    }

    private function buildManifest(
        string $pluginId,
        string $displayName,
        string $classSegment,
        string $description,
        array $capabilities,
        array $hooks,
        string $cleanupMode,
    ): array {
        $settings = [];

        if (in_array('scheduled', $capabilities, true)) {
            $settings[] = [
                'id' => 'schedule_enabled',
                'label' => 'Enable Scheduled Health Checks',
                'type' => 'boolean',
                'default' => false,
            ];
            $settings[] = [
                'id' => 'schedule_cron',
                'label' => 'Schedule Cron',
                'type' => 'text',
                'default' => '0 * * * *',
            ];
        }

        $permissions = ['queue_jobs', 'filesystem_write'];
        if ($hooks !== []) {
            $permissions[] = 'hook_subscriptions';
        }
        if (in_array('scheduled', $capabilities, true)) {
            $permissions[] = 'scheduled_runs';
        }

        return [
            'id' => $pluginId,
            'name' => $displayName,
            'version' => '1.0.0',
            'api_version' => (string) config('plugins.api_version'),
            'description' => $description,
            'entrypoint' => 'Plugin.php',
            'class' => "AppLocalPlugins\\{$classSegment}\\Plugin",
            'capabilities' => $capabilities,
            'hooks' => $hooks,
            'permissions' => array_values(array_unique($permissions)),
            'schema' => [
                'tables' => [],
            ],
            'data_ownership' => [
                'tables' => [],
                'directories' => [
                    "plugin-data/{$pluginId}",
                    "plugin-reports/{$pluginId}",
                ],
                'files' => [],
                'default_cleanup_policy' => $cleanupMode,
            ],
            'settings' => $settings,
            'actions' => [[
                'id' => 'health_check',
                'label' => 'Health Check',
                'dry_run' => true,
                'fields' => [],
            ]],
        ];
    }

    private function renderClassStub(
        string $classSegment,
        string $pluginId,
        string $displayName,
        array $capabilities,
        array $hooks,
        bool $withLifecycleHook,
    ): string {
        $namespace = "AppLocalPlugins\\{$classSegment}";

        $contractMap = config('plugins.capabilities', []);
        $interfaceClasses = ['App\\Plugins\\Contracts\\PluginInterface'];

        foreach ($capabilities as $capability) {
            $interfaceClasses[] = $contractMap[$capability];
        }

        if ($hooks !== []) {
            $interfaceClasses[] = 'App\\Plugins\\Contracts\\HookablePluginInterface';
        }

        if ($withLifecycleHook) {
            $interfaceClasses[] = 'App\\Plugins\\Contracts\\LifecyclePluginInterface';
        }

        $interfaceClasses = array_values(array_unique($interfaceClasses));

        $useClasses = array_merge(
            [
                'App\\Plugins\\Support\\PluginActionResult',
                'App\\Plugins\\Support\\PluginExecutionContext',
            ],
            $interfaceClasses,
        );

        $scheduledCapabilityInterface = $contractMap['scheduled'] ?? null;
        $hasScheduledCapability = $scheduledCapabilityInterface !== null
            && in_array($scheduledCapabilityInterface, $interfaceClasses, true);

        if ($hasScheduledCapability) {
            $useClasses[] = 'Carbon\\CarbonInterface';
            $useClasses[] = 'Cron\\CronExpression';
        }

        if ($withLifecycleHook) {
            $useClasses[] = 'App\\Plugins\\Support\\PluginUninstallContext';
        }

        $useClasses = array_values(array_unique($useClasses));
        sort($useClasses);

        $uses = implode(PHP_EOL, array_map(
            fn (string $class) => 'use '.$class.';',
            $useClasses,
        ));

        $implements = implode(', ', array_map(
            fn (string $class) => class_basename($class),
            $interfaceClasses,
        ));

        $replacements = [
            '{{ namespace }}' => $namespace,
            '{{ uses }}' => $uses,
            '{{ class }}' => 'Plugin',
            '{{ implements }}' => $implements,
            '{{ plugin_id }}' => $pluginId,
            '{{ display_name }}' => $displayName,
            '{{ hook_method }}' => $hooks !== [] ? $this->hookMethodStub($pluginId, $displayName) : '',
            '{{ scheduled_method }}' => $hasScheduledCapability ? $this->scheduledMethodStub() : '',
            '{{ uninstall_method }}' => $withLifecycleHook ? $this->uninstallMethodStub() : '',
        ];

        return strtr(File::get(base_path('stubs/plugins/plugin.class.stub')), $replacements);
    }

    private function writeStarterKitFiles(
        string $pluginPath,
        string $pluginId,
        string $displayName,
        string $classSegment,
        array $capabilities,
        array $hooks,
    ): void {
        $replacements = [
            '{{ plugin_id }}' => $pluginId,
            '{{ display_name }}' => $displayName,
            '{{ class_segment }}' => $classSegment,
            '{{ namespace }}' => "AppLocalPlugins\\{$classSegment}",
            '{{ capabilities_list }}' => $this->bulletList($capabilities, 'No capabilities declared yet.'),
            '{{ hooks_list }}' => $this->bulletList($hooks, 'No hooks declared yet.'),
        ];

        // Skill references go into both .agents/ and .claude/ for cross-tool compatibility
        // .agents/ = Codex, Cursor, Windsurf, Gemini CLI, GitHub Copilot, etc.
        // .claude/ = Claude Code specifically
        $skillPaths = ['.agents/skills/plugin-dev', '.claude/skills/plugin-dev'];

        $files = [
            'README.md' => 'stubs/plugins/README.stub',
            'AGENTS.md' => 'stubs/plugins/AGENTS.stub',
            'CLAUDE.md' => 'stubs/plugins/CLAUDE.stub',
            '.github/workflows/plugin-ci.yml' => 'stubs/plugins/plugin-ci.stub',
            'scripts/package-plugin.sh' => 'stubs/plugins/package-plugin.stub',
            'scripts/validate-plugin.php' => 'stubs/plugins/validate-plugin.stub',
        ];

        // Generate the skill in both discovery paths
        foreach ($skillPaths as $skillDir) {
            $files["{$skillDir}/SKILL.md"] = 'stubs/plugins/plugin-dev-skill.stub';
            $files["{$skillDir}/references/manifest.md"] = 'stubs/plugins/skill-ref-manifest.stub';
            $files["{$skillDir}/references/hooks-and-capabilities.md"] = 'stubs/plugins/skill-ref-hooks.stub';
            $files["{$skillDir}/references/models.md"] = 'stubs/plugins/skill-ref-models.stub';
        }

        foreach ($files as $relativePath => $stubPath) {
            $destinationPath = $pluginPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            File::ensureDirectoryExists(dirname($destinationPath));
            File::put($destinationPath, $this->renderTemplate($stubPath, $replacements));

            if (str_starts_with($relativePath, 'scripts/')) {
                @chmod($destinationPath, 0755);
            }
        }
    }

    private function renderTemplate(string $stubPath, array $replacements): string
    {
        return strtr(File::get(base_path($stubPath)), $replacements);
    }

    private function bulletList(array $values, string $emptyMessage): string
    {
        if ($values === []) {
            return '- '.$emptyMessage;
        }

        return implode(PHP_EOL, array_map(
            fn (string $value) => '- '.$value,
            $values,
        ));
    }

    private function hookMethodStub(string $pluginId, string $displayName): string
    {
        return <<<'PHP'

    public function runHook(string $hook, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return PluginActionResult::success("Hook [{$hook}] received by {{ display_name }}.", [
            'plugin_id' => '{{ plugin_id }}',
            'hook' => $hook,
            'payload' => $payload,
        ]);
    }
PHP;
    }

    private function scheduledMethodStub(): string
    {
        return <<<'PHP'

    public function scheduledActions(CarbonInterface $now, array $settings): array
    {
        if (! ($settings['schedule_enabled'] ?? false)) {
            return [];
        }

        $cron = (string) ($settings['schedule_cron'] ?? '');
        if ($cron === '' || ! CronExpression::isValidExpression($cron)) {
            return [];
        }

        $expression = new CronExpression($cron);
        if (! $expression->isDue($now)) {
            return [];
        }

        return [[
            'type' => 'action',
            'name' => 'health_check',
            'payload' => [
                'source' => 'schedule',
            ],
            'dry_run' => true,
        ]];
    }
PHP;
    }

    private function uninstallMethodStub(): string
    {
        return <<<'PHP'

    public function uninstall(PluginUninstallContext $context): void
    {
        if (! $context->shouldPurge()) {
            return;
        }

        // Add non-declarative purge cleanup here if the plugin ever needs it.
    }
PHP;
    }

    private function normalizeListOption(array|string|null $values): array
    {
        $items = is_array($values) ? $values : [$values];

        return collect($items)
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->flatMap(fn (string $value) => array_map('trim', explode(',', $value)))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->values()
            ->all();
    }
}
