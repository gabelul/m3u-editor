<?php

namespace App\Filament\Resources\PluginInstallReviews\Pages;

use App\Filament\Resources\PluginInstallReviews\PluginInstallReviewResource;
use App\Plugins\PluginManager;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPluginInstallReviews extends ListRecords
{
    protected static string $resource = PluginInstallReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('stage_directory')
                ->label('Stage Local Plugin')
                ->icon('heroicon-o-folder-open')
                ->schema([
                    TextInput::make('path')
                        ->label('Plugin Directory Path')
                        ->required(),
                    Toggle::make('dev_source')
                        ->label('Mark as dev-source plugin')
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    $review = app(PluginManager::class)->stageDirectoryReview(
                        (string) $data['path'],
                        auth()->id(),
                        (bool) ($data['dev_source'] ?? false),
                    );

                    Notification::make()
                        ->success()
                        ->title('Plugin directory staged')
                        ->body("Install review #{$review->id} is ready for validation and scan.")
                        ->send();
                }),
            Action::make('stage_archive')
                ->label('Stage Plugin Archive')
                ->icon('heroicon-o-archive-box')
                ->schema([
                    TextInput::make('archive')
                        ->label('Archive Path')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $review = app(PluginManager::class)->stageArchiveReview(
                        (string) $data['archive'],
                        auth()->id(),
                    );

                    Notification::make()
                        ->success()
                        ->title('Plugin archive staged')
                        ->body("Install review #{$review->id} is ready for validation and scan.")
                        ->send();
                }),
        ];
    }
}
