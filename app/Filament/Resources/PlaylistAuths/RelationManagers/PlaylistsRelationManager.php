<?php

namespace App\Filament\Resources\PlaylistAuths\RelationManagers;

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Services\DateFormatService;
use App\Tables\Columns\PivotNameColumn;
use App\Tables\Columns\PlaylistUrlColumn;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PlaylistsRelationManager extends RelationManager
{
    protected static string $relationship = 'playlists';

    protected static ?string $title = 'Assigned to';

    protected $listeners = ['refreshRelation' => '$refresh'];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('authenticatable_type')
                    ->required()
                    ->label(__('Type of Playlist'))
                    ->live()
                    ->helperText(__('The type of playlist to assign this auth to.'))
                    ->options([
                        Playlist::class => 'Playlist',
                        CustomPlaylist::class => 'Custom Playlist',
                        MergedPlaylist::class => 'Merged Playlist',
                    ])
                    ->default(Playlist::class) // Default to Playlists if no type is selected
                    ->searchable(),

                Select::make('authenticatable_id')
                    ->required()
                    ->label(__('Playlist'))
                    ->helperText(__('Select the playlist you would like to assign this auth to.'))
                    ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                    ->hidden(fn ($get) => $get('authenticatable_type') !== Playlist::class)
                    ->searchable(),
                Select::make('authenticatable_id')
                    ->required()
                    ->label(__('Custom Playlist'))
                    ->helperText(__('Select the playlist you would like to assign this auth to.'))
                    ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                    ->hidden(fn ($get) => $get('authenticatable_type') !== CustomPlaylist::class)
                    ->searchable(),
                Select::make('authenticatable_id')
                    ->required()
                    ->label(__('Merged Playlist'))
                    ->helperText(__('Select the playlist you would like to assign this auth to.'))
                    ->options(MergedPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                    ->hidden(fn ($get) => $get('authenticatable_type') !== MergedPlaylist::class)
                    ->searchable(),

                TextInput::make('playlist_auth_id')
                    ->label(__('Playlist Auth ID'))
                    ->default($this->ownerRecord->id)
                    ->hidden(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                PivotNameColumn::make('playlist_name')
                    ->label(__('Playlist')),
                PlaylistUrlColumn::make('playlist_url')
                    ->label(__('Playlist URL'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Assign Auth to Playlist'))
                    ->modalHeading(__('Assign Auth to Playlist'))
                    ->using(function (array $data): Model {
                        $playlistAuth = $this->ownerRecord;

                        // Get the model to assign to
                        $modelClass = $data['authenticatable_type'];
                        $modelId = $data['authenticatable_id'];
                        $model = $modelClass::findOrFail($modelId);

                        // Use the assignTo method to ensure single assignment
                        $playlistAuth->assignTo($model);

                        // Return the created pivot record for Filament
                        return $playlistAuth->assignedPlaylist;
                    }),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label(__('Remove auth from Playlist'))
                    ->modalHeading(__('Remove Auth'))
                    ->modalDescription(__('Remove auth from Playlist?'))
                    ->modalSubmitActionLabel(__('Remove'))
                    ->icon('heroicon-o-x-circle')
                    ->modalIcon('heroicon-o-x-circle')
                    ->button()
                    ->hiddenLabel(),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label(__('Remove auth'))
                        ->modalHeading(__('Remove Auth'))
                        ->modalDescription(__('Remove auth from selected Playlist?'))
                        ->modalSubmitActionLabel(__('Remove'))
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle'),
                ]),
            ]);
    }
}
