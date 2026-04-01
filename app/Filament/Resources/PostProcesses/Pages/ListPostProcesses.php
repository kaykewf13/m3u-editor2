<?php

namespace App\Filament\Resources\PostProcesses\Pages;

use App\Filament\Resources\PostProcesses\PostProcessResource;
use Filament\Actions;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ListPostProcesses extends ListRecords
{
    protected static string $resource = PostProcessResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Call webhooks, or run local scripts, after playlist sync completion.');
    }

    protected function getHeaderActions(): array
    {
        return [
            // Actions\CreateAction::make(),
            CreateAction::make()
                ->using(function (array $data, string $model): Model {
                    $data['user_id'] = auth()->id();

                    return $model::create($data);
                })
                ->slideOver()
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title(__('Post Process created'))
                        ->body(__('You can now assign Playlists or EPGs.')),
                )->successRedirectUrl(fn ($record): string => EditPostProcess::getUrl(['record' => $record])),

        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id());
    }
}
