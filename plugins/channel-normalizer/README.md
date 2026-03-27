# Channel Normalizer

`channel-normalizer` is a trusted-local plugin for `m3u-editor`.

## Runtime files

- `plugin.json`
- `Plugin.php`

## Declared capabilities

- channel_processor

## Declared hooks

- playlist.synced

## Release workflow

1. Update `plugin.json` and `Plugin.php`.
2. Run `php scripts/validate-plugin.php`.
3. Run `bash scripts/package-plugin.sh`.
4. Publish the generated zip and its `.sha256` file as a GitHub release asset.
5. Install it into `m3u-editor` with reviewed install, scan, and trust.

## Private installs

Private plugins do not need GitHub. Operators can stage the local plugin directory directly:

```bash
php artisan plugins:stage-directory /absolute/path/to/channel-normalizer
php artisan plugins:scan-install <review-id>
php artisan plugins:approve-install <review-id> --trust
```

For Docker deployments, "local" means a path the host/container can already read. It is not a browser-upload flow.

For private plugins in the UI, the recommended path is `Extensions -> Plugin Installs -> Upload Plugin Archive`.

## GitHub release installs

For GitHub-distributed plugins, publish a release asset checksum and stage it with:

```bash
php artisan plugins:stage-github-release \
  https://github.com/<owner>/<repo>/releases/download/<tag>/channel-normalizer.zip \
  --sha256=<published-sha256>
```
