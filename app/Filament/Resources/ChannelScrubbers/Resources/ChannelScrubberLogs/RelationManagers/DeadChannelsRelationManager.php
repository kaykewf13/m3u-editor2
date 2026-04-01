<?php

namespace App\Filament\Resources\ChannelScrubbers\Resources\ChannelScrubberLogs\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DeadChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'deadChannels';

    protected static ?string $title = 'Dead Channels';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'asc')
            ->columns([
                TextColumn::make('title')
                    ->label(__('Channel'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('url')
                    ->label(__('URL'))
                    ->searchable(),
            ])
            ->filters([]);
    }
}
