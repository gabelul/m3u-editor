<?php

namespace App\Plugins\Support;

class PluginActionResult
{
    public function __construct(
        public readonly string $status,
        public readonly bool $success,
        public readonly string $summary,
        public readonly array $data = [],
    ) {}

    public static function success(string $summary, array $data = []): self
    {
        return new self('completed', true, $summary, $data);
    }

    public static function failure(string $summary, array $data = []): self
    {
        return new self('failed', false, $summary, $data);
    }

    public static function cancelled(string $summary, array $data = []): self
    {
        return new self('cancelled', false, $summary, $data);
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'success' => $this->success,
            'summary' => $this->summary,
            'data' => $this->data,
        ];
    }
}
