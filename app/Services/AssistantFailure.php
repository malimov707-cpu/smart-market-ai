<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class AssistantFailure extends RuntimeException implements ShouldntReport
{
    public function __construct(string $message, public readonly int $status = 502)
    {
        parent::__construct($message);
    }
}
