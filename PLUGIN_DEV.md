# Plugin System

`m3u-editor` now ships with a trusted-local plugin kernel for extension work on this fork.

## Principles

- Plugins extend published capabilities instead of reaching into arbitrary internals.
- Discovery is local and explicit. V1 does not support ZIP upload or remote install.
- Long-running work runs through queued invocations.
- Validation happens before a plugin can be trusted.
- Trust is explicit. Discovery does not imply trust.
- Execution requires: installed + enabled + valid + trusted + integrity verified.

## Directory Layout

Plugins live in `plugins/<plugin-id>/`.

Required files:

- `plugin.json`
- entrypoint file referenced by `plugin.json`, usually `Plugin.php`

To scaffold a new local plugin:

```bash
php artisan make:plugin "Acme XML Tools"
```

Useful options:

```bash
php artisan make:plugin "Acme XML Tools" \
  --capability=channel_processor \
  --capability=scheduled \
  --hook=playlist.synced \
  --lifecycle
```

The scaffold creates:

- `plugins/<plugin-id>/plugin.json`
- `plugins/<plugin-id>/Plugin.php`

The generated plugin is designed to validate immediately and includes a simple `health_check` action so operators can exercise it right away from `Tools -> Extensions`.

## Manifest

Example:

```json
{
  "id": "epg-repair",
  "name": "EPG Repair",
  "version": "1.0.0",
  "api_version": "1.0.0",
  "description": "Scans channels for missing or empty EPG mappings.",
  "entrypoint": "Plugin.php",
  "class": "AppLocalPlugins\\EpgRepair\\Plugin",
  "capabilities": ["epg_repair", "scheduled"],
  "hooks": ["epg.cache.generated"],
  "permissions": ["db_read", "queue_jobs", "hook_subscriptions", "scheduled_runs"],
  "schema": {
    "tables": []
  },
  "data_ownership": {
    "directories": ["plugin-reports/epg-repair"],
    "default_cleanup_policy": "preserve"
  },
  "settings": [],
  "actions": []
}
```

Required fields:

- `id`
- `name`
- `entrypoint`
- `class`

Important fields:

- `api_version`: must match the host plugin API version
- `capabilities`: determines which contract interfaces the plugin class must implement
- `hooks`: optional lifecycle hooks the plugin wants to receive
- `permissions`: explicit host-facing declaration of what the plugin expects to do
- `schema`: host-managed plugin-owned table declarations
- `settings`: operator-configurable schema
- `actions`: manual actions exposed in the plugin edit page
- `data_ownership`: plugin-owned tables, files, and directories that uninstall may preserve or purge

## Trust And Integrity

Plugins are **trusted-local**, not sandboxed.

Current trust lifecycle:

- `pending_review`: discovered but not yet trusted
- `trusted`: admin reviewed and pinned the current plugin hashes
- `blocked`: admin explicitly blocked execution

Current integrity states:

- `unknown`: discovered but not yet trusted
- `verified`: current files match the trusted hash snapshot
- `changed`: files changed after trust and need review again
- `missing`: plugin files are missing on disk

Admin review should check:

- manifest permissions
- owned schema
- owned storage paths
- intended hooks/schedules
- current file integrity state

## Lifecycle

The host treats plugin lifecycle states explicitly:

- `Enable`: plugin can run manual actions, hooks, and schedules
- `Disable`: plugin stays installed, but nothing runs
- `Uninstall`: plugin is marked uninstalled and optionally purges plugin-owned data
- `Forget Registry Record`: removes only the `extension_plugins` row; local files remain and discovery can register the plugin again

`Disable` is reversible.

`Uninstall` is the action that changes lifecycle state and drives cleanup.

## Data Ownership

Plugins that persist their own data must declare it in `data_ownership`.

Supported keys:

- `tables`
- `directories`
- `files`
- `default_cleanup_policy`

Cleanup policies:

- `preserve`
- `purge`

### Table naming rules

Plugin-owned tables must start with:

```text
plugin_<plugin_id_with_dashes_replaced_by_underscores>_
```

Example for `epg-repair`:

```text
plugin_epg_repair_scan_candidates
```

### Storage path rules

Plugin-owned files and directories must live under approved storage roots and remain namespaced by plugin id.

Current approved roots:

- `plugin-data/<plugin-id>/...`
- `plugin-reports/<plugin-id>/...`

Examples:

- `plugin-reports/epg-repair`
- `plugin-data/epg-repair/cache/state.json`

Invalid examples:

- `/tmp/epg-repair`
- `storage/app/plugin-reports/epg-repair`
- `plugin-reports/shared`
- `../reports/epg-repair`

The host uses these declarations during uninstall so it can safely preserve or purge plugin-owned artifacts without guessing.

## Permissions

Supported permissions:

- `db_read`
- `db_write`
- `schema_manage`
- `filesystem_read`
- `filesystem_write`
- `network_egress`
- `queue_jobs`
- `hook_subscriptions`
- `scheduled_runs`

Rules:

- hooks require `hook_subscriptions`
- scheduled capability requires `scheduled_runs`
- declared schema requires `schema_manage`
- declared files/directories require `filesystem_write`

`network_egress` is declarative only in this phase. It is shown during review, but not OS-sandboxed.

## Host-Managed Schema

Plugins do not run arbitrary migrations.

Instead, they declare owned tables in `schema.tables`, and the host creates or purges them.

Current supported column types:

- `id`
- `foreignId`
- `string`
- `text`
- `boolean`
- `integer`
- `bigInteger`
- `decimal`
- `json`
- `timestamp`
- `timestamps`

Current supported index types:

- `index`
- `unique`

## Capabilities

Current capabilities:

- `epg_repair`
- `epg_processor`
- `channel_processor`
- `matcher_provider`
- `stream_analysis`
- `scheduled`

## Hooks

Current hook names:

- `playlist.synced`
- `epg.synced`
- `epg.cache.generated`
- `before.epg.map`
- `after.epg.map`
- `before.epg.output.generate`
- `after.epg.output.generate`

## Contracts

Base contract:

```php
interface PluginInterface
{
    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult;
}
```

Optional contracts:

- `HookablePluginInterface`
- `ScheduledPluginInterface`
- capability-specific interfaces in `app/Plugins/Contracts`

## Field Types

Supported schema field types:

- `boolean`
- `number`
- `text`
- `textarea`
- `select`
- `model_select`

`model_select` supports:

- `model`
- `label_attribute`
- `scope: "owned"`

## Commands

- `php artisan plugins:discover`
- `php artisan plugins:validate`
- `php artisan plugins:validate epg-repair`
- `php artisan plugins:verify-integrity`
- `php artisan plugins:trust epg-repair`
- `php artisan plugins:block epg-repair`
- `php artisan make:plugin "Acme XML Tools"`
- `php artisan plugins:doctor`
- `php artisan plugins:uninstall epg-repair --cleanup=preserve`
- `php artisan plugins:reinstall epg-repair`
- `php artisan plugins:forget epg-repair`
- `php artisan plugins:run-scheduled`

## Host Operations

The host now exposes plugin lifecycle operations both in the UI and in Artisan.

- `plugins:doctor`: checks registry integrity, lifecycle state drift, and leftover plugin-owned resources after purge uninstall
- `plugins:uninstall`: marks the plugin uninstalled and optionally preserves or purges declared plugin-owned data
- `plugins:reinstall`: returns an uninstalled plugin to the installed state so it can be enabled again
- `plugins:forget`: removes only the registry row, saved settings, and run history

Operational rule:

- use `forget` only when you intentionally want discovery to recreate the plugin later from disk
- use `uninstall` when you want lifecycle state and cleanup semantics

## Admin Workflow

1. Run plugin discovery.
2. Open `Tools -> Extensions`.
3. Validate a plugin.
4. Review permissions, schema, and integrity.
5. Trust it.
6. Configure settings.
7. Enable it.
8. Run manual actions or let hooks/schedules invoke it.
9. If you remove the plugin later, choose whether uninstall should preserve or purge the declared plugin-owned data.

## Scaffold Workflow

1. Run `php artisan make:plugin "Your Plugin Name"`.
2. Edit the generated `plugin.json` capabilities, hooks, settings, and ownership declarations.
3. Replace the generated `health_check` behavior with the real plugin logic in `Plugin.php`.
4. Run discovery, validation, and trust.
5. Open `Tools -> Extensions` and test the scaffold from the UI.

## Execution Model

- Manual actions are queued through `ExecutePluginInvocation`
- Hook invocations are queued through `PluginHookDispatcher`
- Runs are persisted in `extension_plugin_runs`
- Uninstalled plugins cannot execute until they are explicitly reinstalled
- Untrusted or integrity-changed plugins cannot execute until they are reviewed again
- Uninstall cleanup only touches plugin-owned data declared in the manifest

## Reference Plugin

This fork includes `plugins/epg-repair`.

It demonstrates:

- manifest-driven registration
- dynamic settings/actions
- queued execution
- hook subscription
- scheduled invocation
- dry-run versus apply behavior
