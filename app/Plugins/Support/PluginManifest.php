<?php

namespace App\Plugins\Support;

class PluginManifest
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $version,
        public readonly ?string $apiVersion,
        public readonly ?string $description,
        public readonly string $entrypoint,
        public readonly string $className,
        public readonly array $capabilities,
        public readonly array $hooks,
        public readonly array $settings,
        public readonly array $actions,
        public readonly string $path,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $manifest, string $path): self
    {
        return new self(
            id: (string) ($manifest['id'] ?? basename($path)),
            name: (string) ($manifest['name'] ?? ($manifest['id'] ?? basename($path))),
            version: isset($manifest['version']) ? (string) $manifest['version'] : null,
            apiVersion: isset($manifest['api_version']) ? (string) $manifest['api_version'] : null,
            description: isset($manifest['description']) ? (string) $manifest['description'] : null,
            entrypoint: (string) ($manifest['entrypoint'] ?? 'Plugin.php'),
            className: (string) ($manifest['class'] ?? ''),
            capabilities: array_values($manifest['capabilities'] ?? []),
            hooks: array_values($manifest['hooks'] ?? []),
            settings: array_values($manifest['settings'] ?? []),
            actions: array_values($manifest['actions'] ?? []),
            path: $path,
            raw: $manifest,
        );
    }

    public function entrypointPath(): string
    {
        return rtrim($this->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$this->entrypoint;
    }
}
