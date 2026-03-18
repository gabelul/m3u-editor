<?php

namespace App\Filament\Resources\ExtensionPlugins;

use App\Filament\Resources\ExtensionPlugins\Pages\EditExtensionPlugin;
use App\Filament\Resources\ExtensionPlugins\Pages\ListExtensionPlugins;
use App\Filament\Resources\ExtensionPlugins\Pages\ViewPluginRun;
use App\Filament\Resources\ExtensionPlugins\RelationManagers\LogsRelationManager;
use App\Filament\Resources\ExtensionPlugins\RelationManagers\RunsRelationManager;
use App\Models\ExtensionPlugin;
use App\Plugins\PluginSchemaMapper;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ExtensionPluginResource extends Resource
{
    protected static ?string $model = ExtensionPlugin::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $label = 'Plugin';

    protected static ?string $pluralLabel = 'Plugins';

    protected static string|\UnitEnum|null $navigationGroup = 'Tools';

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canUseTools();
    }

    public static function getNavigationLabel(): string
    {
        return 'Extensions';
    }

    public static function getNavigationSort(): ?int
    {
        return 5;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('plugin_tabs')
                ->persistTabInQueryString()
                ->contained(false)
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Overview')
                        ->icon('heroicon-m-puzzle-piece')
                        ->schema([
                            Section::make('Status')
                                ->compact()
                                ->columns(3)
                                ->schema([
                                    Placeholder::make('plugin_status_snapshot')
                                        ->label('Plugin Status')
                                        ->content(fn (?ExtensionPlugin $record): HtmlString => new HtmlString(self::pluginStatusSnapshot($record))),
                                    Placeholder::make('latest_run_snapshot')
                                        ->label('Latest Run')
                                        ->content(fn (?ExtensionPlugin $record): HtmlString => new HtmlString(self::latestRunSnapshot($record))),
                                    Placeholder::make('automation_snapshot')
                                        ->label('Automation')
                                        ->content(fn (?ExtensionPlugin $record): HtmlString => new HtmlString(self::automationSnapshot($record))),
                                ]),
                            Section::make('Overview')
                                ->compact()
                                ->schema([
                                    Placeholder::make('plugin_identity')
                                        ->label('Plugin')
                                        ->content(fn (?ExtensionPlugin $record): HtmlString => new HtmlString(self::pluginIdentity($record))),
                                    Grid::make(2)
                                        ->schema([
                                            Placeholder::make('capabilities_display')
                                                ->label('Capabilities')
                                                ->content(fn (?ExtensionPlugin $record): HtmlString => new HtmlString(self::pillList(
                                                    collect($record?->capabilities ?? [])
                                                        ->map(fn (string $capability) => str($capability)->replace('_', ' ')->headline())
                                                        ->all(),
                                                    'This plugin has not declared any capabilities yet.',
                                                ))),
                                            Placeholder::make('actions_display')
                                                ->label('Operator Actions')
                                                ->content(fn (?ExtensionPlugin $record): HtmlString => new HtmlString(self::operatorActions($record))),
                                        ]),
                                    Placeholder::make('hooks_display')
                                        ->label('Hook Subscriptions')
                                        ->content(fn (?ExtensionPlugin $record): HtmlString => new HtmlString(self::pillList(
                                            collect($record?->hooks ?? [])->all(),
                                            'This plugin only runs when you trigger one of its header actions.',
                                        ))),
                                ]),
                            Section::make('Advanced Diagnostics')
                                ->compact()
                                ->collapsible()
                                ->collapsed()
                                ->description('Only useful when a plugin is invalid, missing, or behaving unexpectedly.')
                                ->schema([
                                    Grid::make(2)
                                        ->schema([
                                            TextInput::make('validation_status')
                                                ->disabled(),
                                            TextInput::make('source_type')
                                                ->disabled(),
                                            TextInput::make('path')
                                                ->disabled()
                                                ->columnSpanFull(),
                                            TextInput::make('class_name')
                                                ->disabled()
                                                ->columnSpanFull(),
                                        ]),
                                    Textarea::make('validation_errors_json')
                                        ->label('Validation Errors')
                                        ->disabled()
                                        ->rows(6)
                                        ->dehydrated(false)
                                        ->formatStateUsing(fn (?ExtensionPlugin $record) => json_encode($record?->validation_errors ?? [], JSON_PRETTY_PRINT)),
                                ]),
                        ]),
                    Tab::make('Settings')
                        ->icon('heroicon-m-cog-6-tooth')
                        ->schema([
                            Section::make('Settings')
                                ->description('These settings are used by hook-triggered runs, scheduled runs, and as defaults for manual actions.')
                                ->visible(fn (?ExtensionPlugin $record) => filled($record?->settings_schema))
                                ->schema(fn (?ExtensionPlugin $record) => app(PluginSchemaMapper::class)->settingsComponents($record)),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('plugin_id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('version')
                    ->sortable(),
                TextColumn::make('validation_status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'valid' => 'success',
                        'invalid' => 'danger',
                        default => 'warning',
                    }),
                IconColumn::make('available')
                    ->boolean(),
                IconColumn::make('enabled')
                    ->boolean(),
                TextColumn::make('last_validated_at')
                    ->since()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->since()
                    ->sortable(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LogsRelationManager::class,
            RunsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExtensionPlugins::route('/'),
            'edit' => EditExtensionPlugin::route('/{record}/edit'),
            'run' => ViewPluginRun::route('/{record}/runs/{run}'),
        ];
    }

    protected static function pluginStatusSnapshot(?ExtensionPlugin $record): string
    {
        $enabled = $record?->enabled
            ? '<span class="text-success-600 dark:text-success-400 font-medium">Enabled</span>'
            : '<span class="text-gray-600 dark:text-gray-300 font-medium">Disabled</span>';
        $validation = match ($record?->validation_status) {
            'valid' => '<span class="text-success-600 dark:text-success-400 font-medium">Validated and ready</span>',
            'invalid' => '<span class="text-danger-600 dark:text-danger-400 font-medium">Validation failed</span>',
            default => '<span class="text-warning-600 dark:text-warning-400 font-medium">Not validated yet</span>',
        };

        return self::stackedLines([
            $enabled,
            $validation,
            'Plugin ID: <span class="font-mono text-xs">'.e($record?->plugin_id ?? 'unknown').'</span>',
            'API: <span class="font-medium">'.e($record?->api_version ?? 'unknown').'</span>',
        ]);
    }

    protected static function latestRunSnapshot(?ExtensionPlugin $record): string
    {
        $latestRun = $record?->runs()->first();

        if (! $latestRun) {
            return self::mutedMessage('No plugin runs recorded yet. Use the header actions to run the plugin once.');
        }

        return self::stackedLines([
            '<span class="font-medium">'.e(Str::headline($latestRun->status)).'</span>',
            $latestRun->started_at ? 'Started: '.e($latestRun->started_at->toDateTimeString()) : null,
            $latestRun->finished_at ? 'Finished: '.e($latestRun->finished_at->toDateTimeString()) : null,
            $latestRun->summary ? '<span class="text-sm">'.e($latestRun->summary).'</span>' : null,
            '<a href="'.e(self::getUrl('run', ['record' => $record, 'run' => $latestRun])).'" class="inline-flex items-center rounded-md border border-primary-200 bg-primary-50 px-2.5 py-1.5 text-xs font-medium text-primary-700 hover:bg-primary-100 dark:border-primary-800 dark:bg-primary-950/40 dark:text-primary-300 dark:hover:bg-primary-900/60">Open run details</a>',
        ]);
    }

    protected static function automationSnapshot(?ExtensionPlugin $record): string
    {
        $autoScan = $record?->getSetting('auto_scan_on_epg_ready') ? 'Auto scan on EPG cache: enabled' : 'Auto scan on EPG cache: disabled';
        $scheduled = $record?->getSetting('schedule_enabled')
            ? 'Scheduled scans: '.(string) $record->getSetting('schedule_cron', 'enabled')
            : 'Scheduled scans: disabled';

        return self::stackedLines([
            '<span class="font-medium">'.e($autoScan).'</span>',
            '<span class="font-medium">'.e($scheduled).'</span>',
            $record?->getSetting('default_playlist_id') ? 'Default playlist ID: '.e((string) $record->getSetting('default_playlist_id')) : null,
            $record?->getSetting('default_epg_id') ? 'Default EPG ID: '.e((string) $record->getSetting('default_epg_id')) : null,
        ]);
    }

    protected static function pluginIdentity(?ExtensionPlugin $record): string
    {
        if (! $record) {
            return self::mutedMessage('No plugin record loaded.');
        }

        return self::stackedLines([
            '<div class="text-base font-semibold text-gray-950 dark:text-white">'.e($record->name).'</div>',
            '<div class="text-sm text-gray-600 dark:text-gray-300">Version '.e($record->version).' · '.e($record->description ?: 'No description provided.').'</div>',
            '<div class="text-xs text-gray-500 dark:text-gray-400">Use the header actions for one-off runs. Use settings for defaults and automation.</div>',
        ]);
    }

    protected static function operatorActions(?ExtensionPlugin $record): string
    {
        $actions = collect($record?->actions ?? [])
            ->map(function (array $action): string {
                $label = $action['label'] ?? Str::headline((string) ($action['id'] ?? 'Action'));
                $notes = collect([
                    ($action['dry_run'] ?? false) ? 'dry run' : null,
                    ($action['requires_confirmation'] ?? false) ? 'needs confirmation' : null,
                    ($action['destructive'] ?? false) ? 'destructive' : null,
                ])->filter()->implode(' · ');

                return '<div class="rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-800">'.
                    '<div class="font-medium text-gray-950 dark:text-white">'.e($label).'</div>'.
                    '<div class="text-xs text-gray-500 dark:text-gray-400">'.e($notes !== '' ? $notes : 'manual action').'</div>'.
                    '</div>';
            })
            ->implode('');

        if ($actions === '') {
            return self::mutedMessage('No operator actions were declared for this plugin.');
        }

        return '<div class="grid gap-2">'.$actions.'</div>';
    }

    protected static function pillList(array $items, string $emptyMessage): string
    {
        if ($items === []) {
            return self::mutedMessage($emptyMessage);
        }

        $pills = collect($items)
            ->map(fn (string $item) => '<span class="inline-flex items-center rounded-full border border-primary-200 bg-primary-50 px-2.5 py-1 text-xs font-medium text-primary-700 dark:border-primary-800 dark:bg-primary-950/40 dark:text-primary-300">'.e($item).'</span>')
            ->implode(' ');

        return '<div class="flex flex-wrap gap-2">'.$pills.'</div>';
    }

    protected static function stackedLines(array $lines): string
    {
        $content = collect($lines)
            ->filter()
            ->map(fn (string $line) => '<div class="text-sm text-gray-700 dark:text-gray-200">'.$line.'</div>')
            ->implode('');

        return '<div class="space-y-2">'.$content.'</div>';
    }

    protected static function mutedMessage(string $message): string
    {
        return '<div class="text-sm text-gray-500 dark:text-gray-400">'.e($message).'</div>';
    }
}
