<?php

namespace App\Filament\Resources\ExtensionPlugins\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'Execution Log';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
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
                TextColumn::make('summary')
                    ->wrap()
                    ->limit(100),
                TextColumn::make('finished_at')
                    ->since()
                    ->toggleable(),
            ]);
    }
}
