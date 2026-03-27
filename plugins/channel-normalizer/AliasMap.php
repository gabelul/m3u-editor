<?php

namespace AppLocalPlugins\ChannelNormalizer;

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * In-memory index built from YAML alias files.
 *
 * PHP port of the Python normalizer's alias_map.py — loads canonical channel
 * definitions from a directory of YAML files and provides O(1) lookups by
 * stream_id or cleaned title.
 *
 * Files load alphabetically, so naming controls priority:
 *   00_curated.yml loads before 50_it_generated.yml
 *   → curated entries win on conflicts (first-writer-wins)
 *
 * Each YAML entry looks like:
 *   RaiUno.it:
 *     title: "RAI 1"
 *     ids: [RaiUno.it, rai1.it, Rai1.it]
 *     titles: ["rai 1", "rai uno", "raiuno"]
 */
class AliasMap
{
    /** @var array<string, string> stream_id → canonical_id */
    private array $byId = [];

    /** @var array<string, string> cleaned_title → canonical_id */
    private array $byTitle = [];

    /** @var array<string, string> canonical_id → display title */
    private array $displayTitles = [];

    /** How many canonical channel entries we loaded. */
    private int $entryCount = 0;

    /**
     * Load alias files from the given directory path.
     *
     * Scans for *.yml files, loads them alphabetically, and builds
     * reverse-lookup indexes. Skips macOS resource forks (._* files)
     * because ExFAT volumes love generating those.
     *
     * @param string $aliasDir - Absolute path to the aliases directory
     * @return self Fluent return for chaining
     */
    public static function fromDirectory(string $aliasDir): self
    {
        $map = new self;

        if (! File::isDirectory($aliasDir)) {
            return $map;
        }

        // Grab all .yml files, skip macOS resource forks, sort alphabetically
        $files = collect(File::glob($aliasDir . '/*.yml'))
            ->filter(fn (string $f) => ! str_starts_with(basename($f), '._'))
            ->sort()
            ->values();

        foreach ($files as $file) {
            $map->loadFile($file);
        }

        return $map;
    }

    /**
     * Parse one YAML file and merge its entries into the indexes.
     *
     * First-writer-wins: if a stream_id or title was already indexed by
     * an earlier file (e.g. 00_curated.yml), the later file's mapping
     * is silently ignored. This keeps curated data authoritative.
     *
     * @param string $filePath - Absolute path to a .yml alias file
     */
    private function loadFile(string $filePath): void
    {
        if (! File::exists($filePath)) {
            return;
        }

        $data = Yaml::parseFile($filePath);

        if (! is_array($data)) {
            return;
        }

        foreach ($data as $canonicalId => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $this->entryCount++;

            // Display title — first file to define it wins
            if (! isset($this->displayTitles[$canonicalId])) {
                $this->displayTitles[$canonicalId] = $entry['title'] ?? $canonicalId;
            }

            // Index known stream_id values (exact + lowercase)
            foreach ($entry['ids'] ?? [] as $sid) {
                $sid = (string) $sid;
                if (! isset($this->byId[$sid])) {
                    $this->byId[$sid] = $canonicalId;
                }
                $lower = strtolower($sid);
                if (! isset($this->byId[$lower])) {
                    $this->byId[$lower] = $canonicalId;
                }
            }

            // Index known title variations (already expected lowercase)
            foreach ($entry['titles'] ?? [] as $title) {
                $key = strtolower((string) $title);
                if (! isset($this->byTitle[$key])) {
                    $this->byTitle[$key] = $canonicalId;
                }
            }
        }
    }

    /**
     * Try to resolve a channel to its canonical ID.
     *
     * Checks stream_id first (more reliable — provider-assigned identifiers
     * are more stable than display names), then falls back to title matching.
     *
     * @param string $streamId     - The channel's current stream_id
     * @param string $cleanedTitle - The channel's cleaned/normalized title
     * @return string|null Canonical ID (e.g. "RaiUno.it") or null if no match
     */
    public function resolve(string $streamId, string $cleanedTitle): ?string
    {
        return $this->lookupByStreamId($streamId)
            ?? $this->lookupByTitle($cleanedTitle);
    }

    /**
     * Match by stream_id — tries exact first, then case-insensitive.
     *
     * @param string $streamId - The channel's current stream_id
     * @return string|null Canonical ID or null
     */
    public function lookupByStreamId(string $streamId): ?string
    {
        if ($streamId === '') {
            return null;
        }

        return $this->byId[$streamId] ?? $this->byId[strtolower($streamId)] ?? null;
    }

    /**
     * Match by cleaned title — exact lowercase lookup.
     *
     * @param string $cleanedTitle - Lowercase cleaned title (e.g. "rai 1")
     * @return string|null Canonical ID or null
     */
    public function lookupByTitle(string $cleanedTitle): ?string
    {
        if ($cleanedTitle === '') {
            return null;
        }

        return $this->byTitle[$cleanedTitle] ?? null;
    }

    /**
     * Get the display title for a canonical ID.
     *
     * @param string $canonicalId - e.g. "RaiUno.it"
     * @return string Display title (e.g. "RAI 1") or the canonical ID as fallback
     */
    public function getDisplayTitle(string $canonicalId): string
    {
        return $this->displayTitles[$canonicalId] ?? $canonicalId;
    }

    /**
     * Check if a stream_id is already one of our canonical IDs.
     *
     * Channels that have already been normalized will have their stream_id
     * set TO a canonical ID — so we can skip them on subsequent runs.
     *
     * @param string $streamId - The channel's current stream_id
     * @return bool True if it's a key in the alias map
     */
    public function isCanonical(string $streamId): bool
    {
        return isset($this->displayTitles[$streamId]);
    }

    /**
     * Stats about what got loaded — useful for health checks and logging.
     *
     * @return array{entries: int, id_mappings: int, title_mappings: int}
     */
    public function stats(): array
    {
        return [
            'entries' => $this->entryCount,
            'id_mappings' => count($this->byId),
            'title_mappings' => count($this->byTitle),
        ];
    }
}
