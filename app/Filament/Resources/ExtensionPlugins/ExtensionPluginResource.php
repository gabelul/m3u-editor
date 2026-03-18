<?php

namespace App\Filament\Resources\ExtensionPlugins;

use App\Filament\Resources\ExtensionPlugins\Pages\EditExtensionPlugin;
use App\Filament\Resources\ExtensionPlugins\Pages\ListExtensionPlugins;
use App\Filament\Resources\ExtensionPlugins\RelationManagers\LogsRelationManager;
use App\Filament\Resources\ExtensionPlugins\RelationManagers\RunsRelationManager;
use App\Models\ExtensionPlugin;
use App\Plugins\PluginSchemaMapper;
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
                            Section::make('Plugin')
                                ->schema([
                                    Grid::make(2)
                                        ->schema([
                                            TextInput::make('plugin_id')
                                                ->disabled(),
                                            TextInput::make('name')
                                                ->disabled(),
                                            TextInput::make('version')
                                                ->disabled(),
                                            TextInput::make('api_version')
                                                ->label('Plugin API')
                                                ->disabled(),
                                        ]),
                                    Textarea::make('description')
                                        ->rows(3)
                                        ->disabled()
                                        ->columnSpanFull(),
                                ]),
                            Section::make('Capabilities')
                                ->description('What this plugin can do, which hooks it listens to, and which operator actions it exposes.')
                                ->schema([
                                    Textarea::make('capabilities_display')
                                        ->label('Capabilities')
                                        ->disabled()
                                        ->rows(4)
                                        ->dehydrated(false)
                                        ->formatStateUsing(fn (?ExtensionPlugin $record) => collect($record?->capabilities ?? [])
                                            ->map(fn (string $capability) => str($capability)->replace('_', ' ')->headline())
                                            ->implode("\n")),
                                    Textarea::make('hooks_display')
                                        ->label('Hook Subscriptions')
                                        ->disabled()
                                        ->rows(4)
                                        ->dehydrated(false)
                                        ->formatStateUsing(fn (?ExtensionPlugin $record) => collect($record?->hooks ?? [])->implode("\n")),
                                    Textarea::make('actions_display')
                                        ->label('Operator Actions')
                                        ->disabled()
                                        ->rows(5)
                                        ->dehydrated(false)
                                        ->formatStateUsing(fn (?ExtensionPlugin $record) => collect($record?->actions ?? [])
                                            ->map(fn (array $action) => ($action['label'] ?? str($action['id'] ?? 'action')->headline()).' ['.($action['id'] ?? 'unknown').']')
                                            ->implode("\n")),
                                    Textarea::make('latest_run_summary')
                                        ->label('Latest Run Summary')
                                        ->disabled()
                                        ->rows(4)
                                        ->dehydrated(false)
                                        ->formatStateUsing(function (?ExtensionPlugin $record): string {
                                            $latestRun = $record?->runs()->first();

                                            if (! $latestRun) {
                                                return 'No plugin runs recorded yet.';
                                            }

                                            return implode("\n", array_filter([
                                                'Status: '.str($latestRun->status)->headline(),
                                                'Started: '.optional($latestRun->started_at)->toDateTimeString(),
                                                'Finished: '.optional($latestRun->finished_at)->toDateTimeString(),
                                                'Summary: '.($latestRun->summary ?? 'No summary available.'),
                                            ]));
                                        })
                                        ->columnSpanFull(),
                                ]),
                        ]),
                    Tab::make('Runtime')
                        ->icon('heroicon-m-command-line')
                        ->schema([
                            Section::make('Runtime')
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
        ];
    }
}
