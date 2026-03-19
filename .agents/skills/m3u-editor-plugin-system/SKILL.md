---
name: m3u-editor-plugin-system
description: "Use this skill whenever the work involves the m3u-editor plugin system on this repository: designing plugin capabilities, editing the plugin kernel, adding or reviewing plugin manifests, implementing lifecycle rules like discover/validate/enable/disable/uninstall/forget/reinstall, building plugin-owned data stores, or working on the bundled EPG Repair reference plugin. Also use it when creating new plugins, validating plugin cleanup behavior, debugging plugin runs, or extending the Extensions UI. Do not use for generic Laravel features unrelated to plugins."
---

# m3u-editor Plugin System

This repository contains a fork-local plugin kernel. Treat it as a product surface, not as an excuse to reach into arbitrary internals.

## Use This Skill For

- plugin kernel changes under `app/Plugins`, `app/Filament/Resources/ExtensionPlugins`, `config/plugins.php`, and `plugins/*`
- plugin lifecycle work: discover, validate, enable, disable, uninstall, reinstall, forget
- plugin-owned storage rules, cleanup semantics, and uninstall safety
- work on the bundled `plugins/epg-repair` reference plugin
- future plugin scaffolding and capability expansion

## Read First

Before making changes, read these project files:

- `PLUGIN_DEV.md`
- `config/plugins.php`
- `app/Plugins/PluginManager.php`
- `app/Plugins/PluginValidator.php`
- `app/Plugins/Support/PluginManifest.php`
- `app/Models/ExtensionPlugin.php`
- `app/Models/ExtensionPluginRun.php`

If the task touches the reference plugin, also read:

- `plugins/epg-repair/plugin.json`
- `plugins/epg-repair/Plugin.php`

If the task touches run review or evidence UX, also read:

- `app/Filament/Resources/ExtensionPlugins/Pages/EditExtensionPlugin.php`
- `app/Filament/Resources/ExtensionPlugins/Pages/ViewPluginRun.php`
- `resources/views/filament/resources/extension-plugins/pages/view-plugin-run.blade.php`

## Core Rules

- Plugins extend published capabilities. Do not couple plugin authors to random internal services.
- Manifest validation is mandatory before enablement.
- Long-running work must execute through queued plugin invocations.
- Plugin-owned tables, files, and directories must be declared in `data_ownership`.
- `disable`, `uninstall`, and `forget` are different operations. Do not blur them.
- Uninstall cleanup must only touch declared plugin-owned resources.
- If you add plugin-owned persistence, make sure purge uninstall removes it cleanly.

## System Model

- Discovery scans `plugins/<plugin-id>/` and syncs manifests into `extension_plugins`.
- Validation gates execution. Invalid plugins should never be treated as runnable.
- Enable and disable are currently operator-facing UI actions in Filament, not dedicated Artisan commands.
- Manual actions, hooks, and schedules all produce persisted plugin runs with logs, heartbeats, and results.

## Current Capability Model

The host currently recognizes:

- `epg_repair`
- `epg_processor`
- `channel_processor`
- `matcher_provider`
- `stream_analysis`
- `scheduled`

Prefer:

- single active provider for replacement-style capabilities such as `matcher_provider`
- ordered pipelines for processing capabilities
- observer-style hooks for non-mutating reporting and automation

## Validation Workflow

After plugin-kernel or plugin changes, run the host checks in this order:

```bash
php artisan plugins:discover
php artisan plugins:validate
php artisan plugins:doctor
php artisan test tests/Feature/PluginSystemTest.php
```

If you are working in the dev container, use the same commands inside Docker.

## Lifecycle Workflow

Use these commands and preserve their meanings:

```bash
php artisan plugins:discover
php artisan plugins:validate epg-repair
php artisan plugins:uninstall epg-repair --cleanup=preserve
php artisan plugins:uninstall epg-repair --cleanup=purge
php artisan plugins:reinstall epg-repair
php artisan plugins:forget epg-repair
php artisan plugins:run-scheduled
php artisan plugins:recover-stale-runs --minutes=15
```

Rules:

- `Disable`: plugin remains installed but does not execute
- `Uninstall`: lifecycle transition with preserve-or-purge cleanup
- `Forget`: registry cleanup only; files remain on disk and discovery can re-register the plugin
- `Recover stale runs`: marks interrupted runs stale when heartbeat has stopped beyond the configured threshold

## Reference Plugin Expectations

`plugins/epg-repair` is the working example. Use it as the reference shape for:

- manifest-driven registration
- queued actions
- hook invocation
- scheduled invocation
- run persistence
- live activity logging
- review/apply flows
- plugin-owned data cleanup

When improving the reference plugin, keep the plugin generic enough that future plugin authors can copy the patterns without depending on hidden privileges.

## Implementation Notes

- Plugin tables should use the required plugin prefix pattern from `PLUGIN_DEV.md`.
- Plugin file output should stay under `plugin-data/<plugin-id>/...` or `plugin-reports/<plugin-id>/...`.
- If you add a new capability or lifecycle state, update both docs and tests in the same change.
- Prefer compact run payloads; move large reviewable datasets into plugin-owned tables.

## Completion Checklist

Before claiming the plugin system work is done:

1. run discovery, validation, doctor, and focused plugin tests
2. verify lifecycle behavior for disable/uninstall/reinstall/forget
3. verify plugin-owned data is preserved or purged correctly
4. verify the Extensions UI still reflects the current lifecycle and run state clearly

## Guardrails

- Do not document `forget` and `uninstall` as the same thing.
- Do not claim ZIP uploads, remote plugin installs, or a marketplace exist on this fork.
- Do not add undeclared plugin-owned tables, directories, or files.
- Do not claim there are CLI enable/disable commands if you have not added them.
