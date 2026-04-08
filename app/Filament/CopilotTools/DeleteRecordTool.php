<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class DeleteRecordTool extends AbstractResourceTool
{
    public function description(): Stringable|string
    {
        return 'Delete a '.$this->getModelLabel().' by its ID.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The ID of the '.$this->getModelLabel().' to delete')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $record = $this->getBaseQuery()->find($request['id']);

        if (! $record) {
            return $this->getModelLabel().' #'.$request['id'].' not found.';
        }

        $recordKey = $record->getKey();
        $record->delete();

        return 'Deleted '.$this->getModelLabel().' #'.$recordKey.' successfully.';
    }
}
