<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class EditRecordTool extends AbstractResourceTool
{
    public function description(): Stringable|string
    {
        $columns = implode(', ', $this->writableColumns());

        return 'Update an existing '.$this->getModelLabel().'. Updatable fields: '.$columns.'.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        $columns = implode(', ', $this->writableColumns());

        return [
            'id' => $schema->string()->description('The ID of the '.$this->getModelLabel().' to update')->required(),
            'data' => $schema->string()
                ->description('JSON object with fields to update. Available fields: '.$columns.'.')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $record = $this->getBaseQuery()->find($request['id']);

        if (! $record) {
            return $this->getModelLabel().' #'.$request['id'].' not found.';
        }

        $raw = json_decode((string) $request['data'], true);

        if (! is_array($raw)) {
            return 'Invalid JSON provided in data parameter.';
        }

        $data = $this->stripProtectedFields($raw);
        $record->update($data);

        return 'Updated '.$this->getModelLabel().' #'.$record->getKey().' successfully.';
    }
}
