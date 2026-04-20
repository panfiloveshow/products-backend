<?php

namespace App\Domains\Locality\Ingestion;

final readonly class SyncResult
{
    public function __construct(
        public int $inserted,
        public int $updated,
        public int $skipped,
    ) {
    }

    public function total(): int
    {
        return $this->inserted + $this->updated + $this->skipped;
    }

    public function toArray(): array
    {
        return [
            'inserted' => $this->inserted,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'total' => $this->total(),
        ];
    }
}
