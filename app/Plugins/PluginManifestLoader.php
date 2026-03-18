<?php

namespace App\Plugins;

use App\Plugins\Support\PluginManifest;
use RuntimeException;

class PluginManifestLoader
{
    public function load(string $pluginPath): PluginManifest
    {
        $manifestPath = rtrim($pluginPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'plugin.json';

        if (! file_exists($manifestPath)) {
            throw new RuntimeException("Missing plugin manifest: {$manifestPath}");
        }

        $json = file_get_contents($manifestPath);
        $decoded = json_decode($json ?: '', true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid plugin manifest JSON: {$manifestPath}");
        }

        return PluginManifest::fromArray($decoded, $pluginPath);
    }
}
