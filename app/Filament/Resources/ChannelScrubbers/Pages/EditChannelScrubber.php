<?php

namespace App\Filament\Resources\ChannelScrubbers\Pages;

use App\Filament\Resources\ChannelScrubbers\ChannelScrubberResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditChannelScrubber extends EditRecord
{
    protected static string $resource = ChannelScrubberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
