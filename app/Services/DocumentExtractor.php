<?php

declare(strict_types=1);

namespace App\Services;

interface DocumentExtractor
{
    public function availability(): array;

    public function extract(string $contents, string $mime, string $requestKey): array;
}
