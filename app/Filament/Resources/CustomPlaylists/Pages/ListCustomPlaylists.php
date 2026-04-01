<?php

namespace App\Filament\Resources\CustomPlaylists\Pages;

use App\Filament\Resources\CustomPlaylists\CustomPlaylistResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListCustomPlaylists extends ListRecords
{
    protected static string $resource = CustomPlaylistResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Create playlists composed of channels from your other playlists. Head to channels to bulk add channels to your custom playlist.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->successRedirectUrl(fn ($record): string => EditCustomPlaylist::getUrl(['record' => $record])),
        ];
    }
}
