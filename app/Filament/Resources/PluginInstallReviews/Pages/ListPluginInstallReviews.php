<?php

namespace App\Filament\Resources\PluginInstallReviews\Pages;

use App\Filament\Actions\PluginInstallActions;
use App\Filament\Resources\PluginInstallReviews\PluginInstallReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListPluginInstallReviews extends ListRecords
{
    protected static string $resource = PluginInstallReviewResource::class;

    protected function getHeaderActions(): array
    {
        return PluginInstallActions::staging();
    }
}
