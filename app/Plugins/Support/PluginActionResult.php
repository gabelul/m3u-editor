<?php

namespace App\Plugins\Support;

class PluginActionResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $summary,
        public readonly array $data = [],
    ) {}

    public static function success(string $summary, array $data = []): self
    {
        return new self(true, $summary, $data);
    }

    public static function failure(string $summary, array $data = []): self
    {
        return new self(false, $summary, $data);
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'summary' => $this->summary,
            'data' => $this->data,
        ];
    }
}
