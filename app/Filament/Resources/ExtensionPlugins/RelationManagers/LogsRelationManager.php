<?php

namespace App\Filament\Resources\ExtensionPlugins\RelationManagers;

use App\Filament\Resources\ExtensionPlugins\ExtensionPluginResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LogsRelationManager extends RelationManager
{
    protected static string $relationship = 'logs';

    protected static ?string $title = 'Live Activity';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->poll('2s')
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No live activity yet')
            ->emptyStateDescription('Run a plugin action to see step-by-step activity appear here.')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Timestamp')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('level')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'error' => 'danger',
                        'warning' => 'warning',
                        default => 'info',
                    }),
                TextColumn::make('run_reference')
                    ->label('Run')
                    ->state(fn ($record) => $record->run?->action
                        ? str($record->run->action)->headline().' #'.$record->extension_plugin_run_id
                        : str($record->run?->hook ?? 'Hook')->headline().' #'.$record->extension_plugin_run_id)
                    ->url(fn ($record): ?string => $record->run
                        ? ExtensionPluginResource::getUrl('run', [
                            'record' => $this->getOwnerRecord(),
                            'run' => $record->run,
                        ])
                        : null)
                    ->wrap(),
                TextColumn::make('message')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('context_summary')
                    ->label('Context')
                    ->state(function ($record): ?string {
                        $context = $record->context ?? [];

                        if ($context === []) {
                            return null;
                        }

                        return collect($context)
                            ->map(fn ($value, $key) => $key.': '.(is_scalar($value) || $value === null ? json_encode($value) : '[…]'))
                            ->take(4)
                            ->implode("\n");
                    })
                    ->wrap()
                    ->toggleable(),
            ]);
    }
}
