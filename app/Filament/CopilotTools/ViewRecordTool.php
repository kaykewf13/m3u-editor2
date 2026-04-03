<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ViewRecordTool extends AbstractResourceTool
{
    public function description(): Stringable|string
    {
        return 'View a single '.$this->getModelLabel().' by its ID.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The ID of the '.$this->getModelLabel().' to view')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $model = $this->getModelClass();
        $record = $model::find($request['id']);

        if (! $record) {
            return $this->getModelLabel().' #'.$request['id'].' not found.';
        }

        $sensitive = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

        $lines = [$this->getModelLabel().' #'.$record->getKey().':', ''];

        foreach (collect($record->toArray())->forget($sensitive) as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $lines[] = "  {$key}: {$value}";
        }

        return implode("\n", $lines);
    }
}
