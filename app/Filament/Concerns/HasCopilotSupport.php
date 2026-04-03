<?php

namespace App\Filament\Concerns;

use App\Filament\CopilotTools\CreateRecordTool;
use App\Filament\CopilotTools\DeleteRecordTool;
use App\Filament\CopilotTools\EditRecordTool;
use App\Filament\CopilotTools\ListRecordsTool;
use App\Filament\CopilotTools\SearchRecordsTool;
use App\Filament\CopilotTools\ViewRecordTool;

trait HasCopilotSupport
{
    public static function copilotResourceDescription(): ?string
    {
        return 'Manages '.static::getPluralModelLabel().' in the application.';
    }

    public static function copilotTools(): array
    {
        $resource = static::class;

        return [
            new ListRecordsTool($resource),
            new SearchRecordsTool($resource),
            new ViewRecordTool($resource),
            new CreateRecordTool($resource),
            new EditRecordTool($resource),
            new DeleteRecordTool($resource),
        ];
    }
}
