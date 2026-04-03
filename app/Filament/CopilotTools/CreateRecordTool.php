<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateRecordTool extends AbstractResourceTool
{
    public function description(): Stringable|string
    {
        $columns = implode(', ', $this->writableColumns());

        return 'Create a new '.$this->getModelLabel().'. Available fields: '.$columns.'.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        $columns = implode(', ', $this->writableColumns());

        return [
            'data' => $schema->string()
                ->description('JSON object with fields to set. Available fields: '.$columns.'.')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $raw = json_decode((string) $request['data'], true);

        if (! is_array($raw)) {
            return 'Invalid JSON provided in data parameter.';
        }

        $model = $this->getModelClass();
        $data = $this->stripProtectedFields($raw);

        $record = $model::create($data);

        return 'Created '.$this->getModelLabel().' #'.$record->getKey().' successfully.';
    }
}
