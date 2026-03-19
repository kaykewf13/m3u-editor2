<?php

namespace App\Filament\Resources\ExtensionPlugins\Pages;

use App\Filament\Resources\ExtensionPlugins\ExtensionPluginResource;
use App\Plugins\PluginManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListExtensionPlugins extends ListRecords
{
    protected static string $resource = ExtensionPluginResource::class;

    public function mount(): void
    {
        parent::mount();

        app(PluginManager::class)->recoverStaleRuns();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('discover')
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
                }),
        ];
    }
}
