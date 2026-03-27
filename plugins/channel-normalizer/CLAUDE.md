# CLAUDE.md

Work on `channel-normalizer` as a reviewable plugin artifact for `m3u-editor`.

## Architecture

```
channel-normalizer/
├── Plugin.php          # Entry point — hook handler + action router
├── TitleCleaner.php    # Stateless regex pipeline (PHP port of cleaner.py)
├── AliasMap.php        # YAML alias loader with O(1) lookups (PHP port of alias_map.py)
├── plugin.json         # Manifest — settings, actions, hooks, permissions
├── CLAUDE.md           # This file
├── README.md           # User-facing docs
└── scripts/            # Packaging and validation
```

## How it works

1. **playlist.synced** hook fires → Plugin checks if playlist is in configured defaults
2. For each enabled channel: TitleCleaner strips noise → AliasMap resolves canonical ID
3. If matched: updates `stream_id` to canonical ID (and optionally `title_custom`)
4. Reports progress via heartbeat, supports cancellation, respects dry_run

## Alias map

YAML files live at `/opt/normalizer/aliases/` (Docker volume mount from normalizer container).
Format mirrors the Python normalizer's alias map — same files, same structure.
Path is configurable via the `alias_path` setting.

## Expectations

- Keep the runtime surface centered on `plugin.json` and `Plugin.php`.
- Prefer small, explicit manifest changes over hidden behavior.
- Avoid top-level side effects in PHP files.
- Keep release artifacts reproducible with `bash scripts/package-plugin.sh`.
- Update the published checksum whenever the release zip changes.
