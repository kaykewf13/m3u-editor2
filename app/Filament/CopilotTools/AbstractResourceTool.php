<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractResourceTool extends BaseTool
{
    /** @var array<string, list<string>> */
    private static array $columnCache = [];

    /** @var array<string, list<string>> */
    private static array $rawColumnCache = [];

    public function __construct(protected string $resourceClass) {}

    protected function getModelClass(): string
    {
        return $this->resourceClass::getModel();
    }

    protected function getModelLabel(): string
    {
        return $this->resourceClass::getModelLabel();
    }

    protected function getPluralLabel(): string
    {
        return $this->resourceClass::getPluralModelLabel();
    }

    /**
     * Returns a base query scoped through the resource's getEloquentQuery(),
     * which applies HasUserFiltering (user_id scope) automatically.
     */
    protected function getBaseQuery(): Builder
    {
        return $this->resourceClass::getEloquentQuery();
    }

    /**
     * Returns true when the underlying model table has a user_id column,
     * indicating records are user-owned and should have user_id auto-assigned.
     */
    protected function hasUserScope(): bool
    {
        $modelClass = $this->getModelClass();

        if (! isset(self::$rawColumnCache[$modelClass])) {
            $model = new $modelClass;
            self::$rawColumnCache[$modelClass] = $model->getConnection()
                ->getSchemaBuilder()
                ->getColumnListing($model->getTable());
        }

        return in_array('user_id', self::$rawColumnCache[$modelClass]);
    }

    /**
     * Returns the globally-searchable attributes defined on the resource,
     * falling back to ['name'] when none are declared.
     *
     * @return list<string>
     */
    protected function searchableAttributes(): array
    {
        $attrs = $this->resourceClass::getGloballySearchableAttributes();

        return ! empty($attrs) ? $attrs : ['name'];
    }

    /**
     * Returns column names safe to expose for create / edit operations.
     * Excludes user_id — it is auto-managed and never exposed to the AI.
     * Uses the model's own connection so foreign-key'd DB files work correctly.
     *
     * @return list<string>
     */
    protected function writableColumns(): array
    {
        $modelClass = $this->getModelClass();

        if (isset(self::$columnCache[$modelClass])) {
            return self::$columnCache[$modelClass];
        }

        $model = new $modelClass;
        $columns = $model->getConnection()
            ->getSchemaBuilder()
            ->getColumnListing($model->getTable());

        $excluded = [
            'id', 'created_at', 'updated_at', 'deleted_at',
            'password', 'remember_token',
            'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
            'user_id', // auto-managed; ownership must not be set or changed by the AI
        ];

        return self::$columnCache[$modelClass] = array_values(array_diff($columns, $excluded));
    }

    protected function formatRecord(Model $record): string
    {
        $sensitive = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

        return collect($record->toArray())
            ->forget($sensitive)
            ->reject(fn ($value) => is_null($value))
            ->map(function ($value, string $key): string {
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }

                return "{$key}: ".(is_string($value) ? mb_substr($value, 0, 80) : $value);
            })
            ->implode(', ');
    }

    /** @param array<string> $protected */
    protected function stripProtectedFields(array $data, array $protected = []): array
    {
        $always = [
            'id', 'created_at', 'updated_at', 'deleted_at',
            'password', 'remember_token',
            'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
            'user_id', // ownership is never settable via the AI
        ];

        return array_diff_key($data, array_flip(array_merge($always, $protected)));
    }
}
