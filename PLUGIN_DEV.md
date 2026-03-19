# Plugin System

`m3u-editor` now ships with a trusted-local plugin kernel for extension work on this fork.

## Principles

- Plugins extend published capabilities instead of reaching into arbitrary internals.
- Discovery is local and explicit. V1 does not support ZIP upload or remote install.
- Long-running work runs through queued invocations.
- Validation happens before a plugin can be enabled.

## Directory Layout

Plugins live in `plugins/<plugin-id>/`.

Required files:

- `plugin.json`
- entrypoint file referenced by `plugin.json`, usually `Plugin.php`

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
- `settings`: operator-configurable schema
- `actions`: manual actions exposed in the plugin edit page
- `data_ownership`: plugin-owned tables, files, and directories that uninstall may preserve or purge

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
- `php artisan plugins:run-scheduled`

## Admin Workflow

1. Run plugin discovery.
2. Open `Tools -> Extensions`.
3. Validate a plugin.
4. Configure settings.
5. Enable it.
6. Run manual actions or let hooks/schedules invoke it.
7. If you remove the plugin later, choose whether uninstall should preserve or purge the declared plugin-owned data.

## Execution Model

- Manual actions are queued through `ExecutePluginInvocation`
- Hook invocations are queued through `PluginHookDispatcher`
- Runs are persisted in `extension_plugin_runs`
- Uninstalled plugins cannot execute until they are explicitly reinstalled
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
