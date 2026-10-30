<?php

declare(strict_types=1);

namespace App\Services;

class UnavailableFileScanner implements FileScanner
{
    public function scan(string $path): string
    {
        return 'unavailable';
    }
}
