<?php

namespace App\Filament\Resources\ExtensionPlugins\RelationManagers;

use App\Filament\Resources\ExtensionPlugins\ExtensionPluginResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'Run History';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->poll('3s')
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn ($record): string => ExtensionPluginResource::getUrl('run', [
                'record' => $this->getOwnerRecord(),
                'run' => $record,
            ]))
            ->emptyStateHeading('No run history yet')
            ->emptyStateDescription('Queue a plugin action from the page header to create the first run.')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Queued At')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        'running' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('trigger')
                    ->badge(),
                TextColumn::make('invocation_type')
                    ->badge(),
                TextColumn::make('action')
                    ->toggleable(),
                TextColumn::make('hook')
                    ->toggleable(),
                IconColumn::make('dry_run')
                    ->boolean(),
                TextColumn::make('result.data.totals.repair_candidates')
                    ->label('Candidates')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('result.data.totals.repairs_applied')
                    ->label('Applied')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('summary')
                    ->wrap()
                    ->limit(100),
                TextColumn::make('finished_at')
                    ->since()
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record): string => ExtensionPluginResource::getUrl('run', [
                        'record' => $this->getOwnerRecord(),
                        'run' => $record,
                    ])),
            ]);
    }
}
