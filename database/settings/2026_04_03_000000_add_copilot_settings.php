<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.copilot_enabled')) {
            $this->migrator->add('general.copilot_enabled', false);
        }
        if (! $this->migrator->exists('general.copilot_mgmt_enabled')) {
            $this->migrator->add('general.copilot_mgmt_enabled', false);
        }
        if (! $this->migrator->exists('general.copilot_provider')) {
            $this->migrator->add('general.copilot_provider', null);
        }
        if (! $this->migrator->exists('general.copilot_model')) {
            $this->migrator->add('general.copilot_model', null);
        }
        if (! $this->migrator->exists('general.copilot_api_key')) {
            $this->migrator->add('general.copilot_api_key', null);
        }
        if (! $this->migrator->exists('general.copilot_system_prompt')) {
            $this->migrator->add('general.copilot_system_prompt', null);
        }
        if (! $this->migrator->exists('general.copilot_global_tools')) {
            $this->migrator->add('general.copilot_global_tools', null);
        }
        if (! $this->migrator->exists('general.copilot_quick_actions')) {
            $this->migrator->add('general.copilot_quick_actions', null);
        }
    }
};
