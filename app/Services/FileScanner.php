<?php

declare(strict_types=1);

namespace App\Services;

interface FileScanner
{
    /** Return clean, infected or unavailable. Implementations must not log file contents. */
    public function scan(string $path): string;
}
