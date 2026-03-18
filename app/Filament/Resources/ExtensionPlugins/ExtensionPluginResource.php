<?php

namespace App\Filament\Resources\ExtensionPlugins;

use App\Filament\Resources\ExtensionPlugins\Pages\EditExtensionPlugin;
use App\Filament\Resources\ExtensionPlugins\Pages\ListExtensionPlugins;
use App\Filament\Resources\ExtensionPlugins\RelationManagers\RunsRelationManager;
use App\Models\ExtensionPlugin;
use App\Plugins\PluginSchemaMapper;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
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
            Section::make('Settings')
                ->visible(fn (?ExtensionPlugin $record) => filled($record?->settings_schema))
                ->schema(fn (?ExtensionPlugin $record) => app(PluginSchemaMapper::class)->settingsComponents($record)),
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
