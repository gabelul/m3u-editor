<?php

namespace App\Filament\Resources\PluginInstallReviews\Pages;

use App\Filament\Resources\PluginInstallReviews\PluginInstallReviewResource;
use App\Models\PluginInstallReview;
use App\Plugins\PluginManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPluginInstallReview extends EditRecord
{
    protected static string $resource = PluginInstallReviewResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $record;
    }

    protected function getHeaderActions(): array
    {
        /** @var PluginInstallReview $record */
        $record = $this->record;

        return [
            Action::make('scan')
                ->label('Run ClamAV Scan')
                ->icon('heroicon-o-shield-check')
                ->action(function () use ($record): void {
                    $review = app(PluginManager::class)->scanInstallReview($record);

                    Notification::make()
                        ->title('Scan completed')
                        ->body($review->scan_summary ?: "Scan status: {$review->scan_status}")
                        ->color($review->scan_status === 'clean' ? 'success' : 'warning')
                        ->send();

                    $this->refreshFormData([
                        'status',
                        'scan_status',
                        'scan_summary',
                        'scan_details_json',
                    ]);
                }),
            Action::make('approve')
                ->label('Install Review')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->action(function () use ($record): void {
                    $review = app(PluginManager::class)->approveInstallReview($record, false, auth()->id());

                    Notification::make()
                        ->success()
                        ->title('Plugin installed')
                        ->body("Install review #{$review->id} installed [{$review->plugin_id}].")
                        ->send();

                    $this->refreshFormData(['status', 'installed_path', 'installed_at']);
                }),
            Action::make('install_and_trust')
                ->label('Install And Trust')
                ->icon('heroicon-o-shield-check')
                ->color('success')
                ->requiresConfirmation()
                ->action(function () use ($record): void {
                    $review = app(PluginManager::class)->approveInstallReview($record, true, auth()->id());

                    Notification::make()
                        ->success()
                        ->title('Plugin installed and trusted')
                        ->body("Install review #{$review->id} installed and trusted [{$review->plugin_id}].")
                        ->send();

                    $this->refreshFormData(['status', 'installed_path', 'installed_at']);
                }),
            Action::make('reject')
                ->label('Reject Review')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function () use ($record): void {
                    $review = app(PluginManager::class)->rejectInstallReview($record, auth()->id());

                    Notification::make()
                        ->success()
                        ->title('Install review rejected')
                        ->body("Install review #{$review->id} was rejected.")
                        ->send();

                    $this->refreshFormData(['status', 'review_notes']);
                }),
            Action::make('discard')
                ->label('Discard Review')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->hidden(fn () => $record->status === 'installed')
                ->requiresConfirmation()
                ->action(function () use ($record): void {
                    app(PluginManager::class)->discardInstallReview($record);

                    Notification::make()
                        ->success()
                        ->title('Install review discarded')
                        ->send();

                    $this->redirect(PluginInstallReviewResource::getUrl());
                }),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
