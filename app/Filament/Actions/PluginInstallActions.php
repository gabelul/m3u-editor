<?php

namespace App\Filament\Actions;

use App\Filament\Resources\PluginInstallReviews\PluginInstallReviewResource;
use App\Plugins\PluginManager;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;

class PluginInstallActions
{
    /**
     * Build the shared action for refreshing the plugin registry from disk.
     */
    public static function discover(): Action
    {
        return Action::make('discover')
            ->label('Discover Plugins')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (): bool => auth()->user()?->canManagePlugins() ?? false)
            ->action(function (): void {
                $plugins = app(PluginManager::class)->discover();

                Notification::make()
                    ->success()
                    ->title('Plugin discovery completed')
                    ->body('Synced '.count($plugins).' plugin(s) into the registry.')
                    ->send();
            });
    }

    /**
     * Build the shared navigation action for the plugin install queue.
     */
    public static function pluginInstallsLink(): Action
    {
        return Action::make('plugin_installs')
            ->label('Plugin Installs')
            ->icon('heroicon-o-archive-box')
            ->visible(fn (): bool => auth()->user()?->canManagePlugins() ?? false)
            ->url(PluginInstallReviewResource::getUrl());
    }

    /**
     * Build the shared staging actions used by the install queue and dashboard.
     *
     * @return array<int, Action>
     */
    public static function staging(): array
    {
        return [
            Action::make('stage_directory')
                ->label('Stage Local Plugin')
                ->icon('heroicon-o-folder-open')
                ->visible(fn (): bool => auth()->user()?->canManagePlugins() ?? false)
                ->schema([
                    TextInput::make('path')
                        ->label('Plugin Directory Path')
                        ->required()
                        ->helperText('Use a path the host/container can already read. This action does not upload files from the browser.'),
                    Toggle::make('dev_source')
                        ->label('Mark as dev-source plugin')
                        ->default(false)
                        ->helperText('For configured dev directories only. Do not use this path for production installs.'),
                ])
                ->action(function (array $data): void {
                    $review = app(PluginManager::class)->stageDirectoryReview(
                        (string) $data['path'],
                        auth()->id(),
                        (bool) ($data['dev_source'] ?? false),
                    );

                    Notification::make()
                        ->success()
                        ->title('Plugin install staged')
                        ->body("Plugin install #{$review->id} is ready for validation and scan.")
                        ->send();
                }),
            Action::make('upload_archive')
                ->label('Upload Plugin Archive')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => auth()->user()?->canManagePlugins() ?? false)
                ->schema([
                    FileUpload::make('archive_upload')
                        ->label('Plugin Archive')
                        ->required()
                        ->disk('local')
                        ->visibility('private')
                        ->directory((string) config('plugins.upload_directory', 'plugin-review-uploads'))
                        ->moveFiles()
                        ->preserveFilenames()
                        ->acceptedFileTypes([
                            'application/zip',
                            'application/x-zip-compressed',
                            'application/x-compressed',
                            'multipart/x-zip',
                            'application/x-tar',
                            'application/gzip',
                            'application/x-gzip',
                        ])
                        ->maxSize((int) ceil(((int) config('plugins.archive_limits.max_archive_bytes', 50 * 1024 * 1024)) / 1024))
                        ->helperText('Upload a plugin zip, tar, or tar.gz archive. The server will stage, validate, and scan it through plugin installs.'),
                ])
                ->action(function (array $data): void {
                    $review = app(PluginManager::class)->stageUploadedArchiveReview(
                        (string) $data['archive_upload'],
                        auth()->id(),
                    );

                    Notification::make()
                        ->success()
                        ->title('Uploaded plugin archive staged')
                        ->body("Plugin install #{$review->id} is ready for validation and scan.")
                        ->send();
                }),
            Action::make('stage_archive')
                ->label('Stage Plugin Archive')
                ->icon('heroicon-o-archive-box')
                ->visible(fn (): bool => auth()->user()?->canManagePlugins() ?? false)
                ->schema([
                    TextInput::make('archive')
                        ->label('Archive Path')
                        ->required()
                        ->helperText('Use a zip/tar path the host/container can already read. This action does not upload files from the browser.'),
                ])
                ->action(function (array $data): void {
                    $review = app(PluginManager::class)->stageArchiveReview(
                        (string) $data['archive'],
                        auth()->id(),
                    );

                    Notification::make()
                        ->success()
                        ->title('Plugin archive staged')
                        ->body("Plugin install #{$review->id} is ready for validation and scan.")
                        ->send();
                }),
            Action::make('stage_github_release')
                ->label('Stage GitHub Release')
                ->icon('heroicon-o-cloud-arrow-down')
                ->visible(fn (): bool => auth()->user()?->canManagePlugins() ?? false)
                ->schema([
                    TextInput::make('url')
                        ->label('Release Asset URL')
                        ->required()
                        ->helperText('Use the GitHub release asset URL from the published release.'),
                    TextInput::make('sha256')
                        ->label('Expected SHA-256')
                        ->required()
                        ->helperText('Pin the published release checksum before the host downloads the archive.'),
                ])
                ->action(function (array $data): void {
                    $review = app(PluginManager::class)->stageGithubReleaseReview(
                        (string) $data['url'],
                        (string) $data['sha256'],
                        auth()->id(),
                    );

                    Notification::make()
                        ->success()
                        ->title('GitHub release staged')
                        ->body("Plugin install #{$review->id} is ready for validation and scan.")
                        ->send();
                }),
        ];
    }
}
